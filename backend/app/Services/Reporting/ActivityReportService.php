<?php

namespace App\Services\Reporting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Report I — Financial Activity and Audit.
 *
 * A chronological union of the authoritative financial event sources. Each row
 * keeps its actor, timestamp, reference and reason. Credit-note issuance is
 * reported once, from the commercial-document history, rather than twice.
 */
final class ActivityReportService
{
    public const TYPES = [
        'receipt', 'allocation', 'allocation_reversal', 'credit_designation', 'credit_application',
        'credit_reversal', 'refund', 'refund_requested', 'refund_approved', 'refund_rejected',
        'refund_submitted', 'refund_submitting', 'refund_confirmed', 'refund_failed',
        'credit_note_issued', 'credit_note_voided',
    ];

    /** Raw refund_events actions, mapped to `refund_<action>` in the response. */
    public const REFUND_ACTIONS = ['requested', 'approved', 'rejected', 'submitted', 'submitting', 'confirmed', 'failed', 'provider_error'];

    public function register(array $filters, ReportPeriod $period): LengthAwarePaginator
    {
        return $this->events($filters, $period)
            ->orderByDesc('event_at')->orderByDesc('source_id')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (object $row) => $this->row($row));
    }

    public function exportRows(array $filters, ReportPeriod $period): LazyCollection
    {
        return $this->events($filters, $period)->orderByDesc('event_at')->orderByDesc('source_id')->cursor()
            ->map(fn (object $row) => $this->row($row))
            ->take(50000);
    }

    private function events(array $filters, ReportPeriod $period): Builder
    {
        $query = DB::query()->fromSub($this->union($filters, $period), 'events');

        if (filled($filters['event_type'] ?? null)) {
            $type = (string) $filters['event_type'];
            if ($type === 'refund') {
                $query->whereIn('event_type', self::REFUND_ACTIONS);
            } elseif (str_starts_with($type, 'refund_')) {
                $query->where('event_type', substr($type, strlen('refund_')));
            } elseif (str_starts_with($type, 'credit_note_')) {
                $query->where('event_type', substr($type, strlen('credit_note_')));
            } else {
                $query->where('event_type', $type);
            }
        }

        if (filled($filters['currency'] ?? null)) {
            $query->where('currency', $filters['currency']);
        }

        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn (Builder $search) => $search
                ->where('reference', 'like', $term)
                ->orWhere('subject', 'like', $term)
                ->orWhere('actor', 'like', $term));
        }

        return $query;
    }

    private function union(array $filters, ReportPeriod $period): Builder
    {
        [$from, $to] = $period->bounds();
        $currency = $filters['currency'] ?? null;

        $receipts = DB::table('payment_transactions as pt')
            ->leftJoin('users as u', 'u.id', '=', 'pt.confirmed_by')
            ->where('pt.status', 'succeeded')
            ->whereNotNull('pt.received_at')
            ->whereBetween('pt.received_at', [$from, $to])
            ->when($currency, fn (Builder $query) => $query->where('pt.currency', $currency))
            ->selectRaw("pt.id as source_id, pt.received_at as event_at, 'receipt' as event_type, pt.transaction_reference as reference, u.name as actor, pt.amount as amount, pt.currency as currency, pt.payment_method as subject, NULL as reason");

        $allocations = DB::table('payment_allocation_operations as po')
            ->join('payment_allocations as pa', 'pa.payment_allocation_operation_id', '=', 'po.id')
            ->join('payment_transactions as pt', 'pt.id', '=', 'po.payment_transaction_id')
            ->leftJoin('users as u', 'u.id', '=', 'po.allocated_by')
            ->whereBetween('po.allocated_at', [$from, $to])
            ->when($currency, fn (Builder $query) => $query->where('pa.currency', $currency))
            ->groupBy('po.id', 'po.operation_reference', 'po.allocated_at', 'u.name', 'pt.transaction_reference', 'po.reason', 'pa.currency')
            ->selectRaw("po.id as source_id, po.allocated_at as event_at, 'allocation' as event_type, po.operation_reference as reference, u.name as actor, SUM(pa.amount) as amount, pa.currency as currency, pt.transaction_reference as subject, po.reason as reason");

        $reversals = DB::table('payment_allocation_reversals as pr')
            ->join('payment_allocations as pa', 'pa.id', '=', 'pr.payment_allocation_id')
            ->join('payment_allocation_operations as po', 'po.id', '=', 'pa.payment_allocation_operation_id')
            ->join('payment_transactions as pt', 'pt.id', '=', 'po.payment_transaction_id')
            ->leftJoin('users as u', 'u.id', '=', 'pr.reversed_by')
            ->whereBetween('pr.reversed_at', [$from, $to])
            ->when($currency, fn (Builder $query) => $query->where('pa.currency', $currency))
            ->selectRaw("pr.id as source_id, pr.reversed_at as event_at, 'allocation_reversal' as event_type, pr.operation_reference as reference, u.name as actor, pa.amount as amount, pa.currency as currency, pt.transaction_reference as subject, pr.reason as reason");

        $credit = DB::table('customer_credit_ledger_entries as e')
            ->leftJoin('customers as c', 'c.id', '=', 'e.customer_id')
            ->leftJoin('organisations as org', 'org.id', '=', 'e.organisation_id')
            ->leftJoin('commercial_documents as cd', 'cd.id', '=', 'e.commercial_document_id')
            ->whereIn('e.entry_type', ['designation', 'application', 'reversal'])
            ->whereBetween('e.created_at', [$from, $to])
            ->when($currency, fn (Builder $query) => $query->where('e.currency', $currency))
            ->selectRaw("e.id as source_id, e.created_at as event_at, CASE e.entry_type WHEN 'designation' THEN 'credit_designation' WHEN 'application' THEN 'credit_application' ELSE 'credit_reversal' END as event_type, e.operation_reference as reference, e.actor_name_snapshot as actor, e.amount as amount, e.currency as currency, COALESCE(c.name, org.name, cd.document_number) as subject, e.reason as reason");

        $refunds = DB::table('refund_events as re')
            ->join('refund_requests as rr', 'rr.id', '=', 're.refund_request_id')
            ->whereBetween('re.created_at', [$from, $to])
            ->when($currency, fn (Builder $query) => $query->where('rr.currency', $currency))
            ->selectRaw("re.id as source_id, re.created_at as event_at, re.action as event_type, rr.refund_reference as reference, re.actor_name_snapshot as actor, rr.amount as amount, rr.currency as currency, rr.refund_reference as subject, re.reason as reason");

        $creditNotes = DB::table('commercial_document_histories as h')
            ->join('commercial_documents as cn', 'cn.id', '=', 'h.commercial_document_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->where('cn.document_type', 'credit_note')
            ->whereIn('h.action', ['issued', 'voided'])
            ->whereBetween('h.created_at', [$from, $to])
            ->when($currency, fn (Builder $query) => $query->where('cn.currency', $currency))
            ->selectRaw("h.id as source_id, h.created_at as event_at, h.action as event_type, cn.document_number as reference, u.name as actor, cn.total as amount, cn.currency as currency, cn.document_number as subject, NULL as reason");

        return $receipts
            ->unionAll($allocations)
            ->unionAll($reversals)
            ->unionAll($credit)
            ->unionAll($refunds)
            ->unionAll($creditNotes);
    }

    private function row(object $row): array
    {
        $type = match (true) {
            in_array($row->event_type, self::REFUND_ACTIONS, true) => 'refund_'.$row->event_type,
            in_array($row->event_type, ['issued', 'voided'], true) => 'credit_note_'.$row->event_type,
            default => $row->event_type,
        };

        return [
            'id' => (int) $row->source_id,
            'event_at' => $row->event_at,
            'event_type' => $type,
            'reference' => $row->reference,
            'subject' => $row->subject,
            'actor' => $row->actor,
            'amount' => $row->amount !== null ? Money::decimal(Money::minor($row->amount)) : null,
            'currency' => $row->currency,
            'reason' => $row->reason,
        ];
    }
}
