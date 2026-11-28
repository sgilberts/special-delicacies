<?php

namespace App\Services\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Single source for "what a receipt is worth right now".
 *
 * The derived per-receipt amounts are computed in SQL subqueries so reports
 * never load the ledger into PHP memory and never issue a query per row:
 *
 *   allocated  = active payment allocations (reversed allocations excluded)
 *   designated = cash designated as credit, net of credit reversals
 *   reserved   = live refund requests (pipeline + in flight + confirmed)
 *   available  = amount - allocated - designated
 *   refundable = available - reserved
 */
final class ReceiptLedger
{
    public const LIVE_REFUND_STATUSES = ['requested', 'approved', 'submitting', 'submitted', 'confirmed'];

    public const AVAILABLE_SQL = '(pt.amount - COALESCE(alloc.amount, 0) - (COALESCE(design.amount, 0) - COALESCE(design_rev.amount, 0)))';

    public const REFUNDABLE_SQL = '('.self::AVAILABLE_SQL.' - COALESCE(refunds.amount, 0))';

    public const RECEIPT_DATE_SQL = 'COALESCE(pt.received_at, pt.created_at)';

    public function activeAllocationsSub(): Builder
    {
        return DB::table('payment_allocations as pa')
            ->join('payment_allocation_operations as po', 'po.id', '=', 'pa.payment_allocation_operation_id')
            ->leftJoin('payment_allocation_reversals as pr', 'pr.payment_allocation_id', '=', 'pa.id')
            ->whereNull('pr.id')
            ->groupBy('po.payment_transaction_id')
            ->selectRaw('po.payment_transaction_id as pid, SUM(pa.amount) as amount');
    }

    public function designationsSub(): Builder
    {
        return DB::table('customer_credit_ledger_entries')
            ->where('entry_type', 'designation')
            ->whereNotNull('source_payment_transaction_id')
            ->groupBy('source_payment_transaction_id')
            ->selectRaw('source_payment_transaction_id as pid, SUM(amount) as amount');
    }

    public function designationReversalsSub(): Builder
    {
        return DB::table('customer_credit_ledger_entries as rev')
            ->join('customer_credit_ledger_entries as orig', 'orig.id', '=', 'rev.reverses_entry_id')
            ->where('orig.entry_type', 'designation')
            ->where('rev.entry_type', 'reversal')
            ->groupBy('orig.source_payment_transaction_id')
            ->selectRaw('orig.source_payment_transaction_id as pid, SUM(rev.amount) as amount');
    }

    public function liveRefundsSub(): Builder
    {
        return DB::table('refund_requests')
            ->whereIn('status', self::LIVE_REFUND_STATUSES)
            ->groupBy('payment_transaction_id')
            ->selectRaw('payment_transaction_id as pid, SUM(amount) as amount');
    }

    public function baseQuery(): Builder
    {
        return DB::table('payment_transactions as pt')
            ->leftJoin('orders as o', 'o.id', '=', 'pt.order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'pt.customer_id')
            ->leftJoin('organisations as org', 'org.id', '=', 'pt.organisation_id')
            ->leftJoin('users as confirmer', 'confirmer.id', '=', 'pt.confirmed_by')
            ->leftJoinSub($this->activeAllocationsSub(), 'alloc', 'alloc.pid', '=', 'pt.id')
            ->leftJoinSub($this->designationsSub(), 'design', 'design.pid', '=', 'pt.id')
            ->leftJoinSub($this->designationReversalsSub(), 'design_rev', 'design_rev.pid', '=', 'pt.id')
            ->leftJoinSub($this->liveRefundsSub(), 'refunds', 'refunds.pid', '=', 'pt.id')
            ->select([
                'pt.id', 'pt.transaction_reference', 'pt.provider', 'pt.provider_reference', 'pt.payment_method',
                'pt.amount', 'pt.currency', 'pt.status', 'pt.payment_purpose', 'pt.verified_at', 'pt.received_at',
                'pt.reporting_date', 'pt.created_at', 'pt.customer_id', 'pt.organisation_id', 'pt.order_id',
                'o.order_number', 'o.customer_name as order_customer_name',
                'c.name as customer_name', 'c.email as customer_email', 'c.phone as customer_phone',
                'org.name as organisation_name', 'org.reference as organisation_reference',
                'confirmer.name as confirmer_name',
                'alloc.amount as allocated_amount', 'design.amount as designated_amount',
                'design_rev.amount as designated_reversed_amount', 'refunds.amount as refund_reserved_amount',
            ]);
    }

    /** Replace the select list with an aggregate expression while keeping the report filters. */
    public function aggregate(array $filters, ReportPeriod $period, string $selectSql, array $bindings = []): Builder
    {
        $query = $this->applyFilters($this->baseQuery(), $filters, $period);
        $query->select(DB::raw($selectSql));
        if ($bindings !== []) {
            $query->setBindings($bindings, 'select');
        }

        return $query;
    }

    public function applyPeriod(Builder $query, ReportPeriod $period): Builder
    {
        [$from, $to] = $period->bounds();

        return $query->whereRaw(self::RECEIPT_DATE_SQL.' BETWEEN ? AND ?', [$from, $to]);
    }

    public function applyFilters(Builder $query, array $filters, ReportPeriod $period): Builder
    {
        $this->applyPeriod($query, $period);

        if (filled($filters['currency'] ?? null)) {
            $query->where('pt.currency', $filters['currency']);
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('pt.status', $filters['status']);
        }
        if (filled($filters['method'] ?? null)) {
            $query->where('pt.payment_method', $filters['method']);
        }
        if (filled($filters['provider'] ?? null)) {
            $query->where('pt.provider', $filters['provider']);
        }
        if (filled($filters['purpose'] ?? null)) {
            $query->where('pt.payment_purpose', $filters['purpose']);
        }
        if (filled($filters['payer_type'] ?? null)) {
            $filters['payer_type'] === 'organisation'
                ? $query->whereNotNull('pt.organisation_id')
                : $query->whereNotNull('pt.customer_id');
        }
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn (Builder $search) => $search
                ->where('pt.transaction_reference', 'like', $term)
                ->orWhere('pt.provider_reference', 'like', $term)
                ->orWhere('o.order_number', 'like', $term)
                ->orWhere('c.name', 'like', $term)
                ->orWhere('org.name', 'like', $term));
        }

        return $query;
    }

    public function row(object $row, bool $withContacts = false): array
    {
        $amount = Money::minor($row->amount);
        $allocated = Money::minor($row->allocated_amount);
        $designated = max(0, Money::minor($row->designated_amount) - Money::minor($row->designated_reversed_amount));
        $reserved = Money::minor($row->refund_reserved_amount);
        $available = max(0, $amount - $allocated - $designated);

        $payer = $this->payer($row);
        if ($withContacts) {
            $payer['email'] = $row->customer_email ?? null;
            $payer['phone'] = $row->customer_phone ?? null;
        }

        $receivedAt = $row->received_at ?: $row->created_at;

        return [
            'id' => (int) $row->id,
            'transaction_reference' => $row->transaction_reference,
            'provider_reference' => $row->provider_reference,
            'payer' => $payer,
            'order_number' => $row->order_number,
            'payment_purpose' => $row->payment_purpose,
            'payment_method' => $row->payment_method,
            'provider' => $row->provider,
            'currency' => $row->currency,
            'amount' => Money::decimal($amount),
            'allocated_amount' => Money::decimal($allocated),
            'designated_amount' => Money::decimal($designated),
            'refund_reserved_amount' => Money::decimal($reserved),
            'available_amount' => Money::decimal($available),
            'refundable_amount' => Money::decimal(max(0, $available - $reserved)),
            'status' => $row->status,
            'verification_status' => $this->verificationStatus($row),
            'received_at' => $receivedAt,
            'reporting_date' => $row->reporting_date,
            'confirmed_by' => $row->confirmer_name,
            'age_days' => $receivedAt ? max(0, (int) floor((time() - strtotime($receivedAt)) / 86400)) : null,
        ];
    }

    public function payer(object $row): array
    {
        if ($row->organisation_id !== null) {
            return ['type' => 'organisation', 'id' => (int) $row->organisation_id, 'name' => $row->organisation_name];
        }
        if ($row->customer_id !== null) {
            return ['type' => 'customer', 'id' => (int) $row->customer_id, 'name' => $row->customer_name];
        }

        return ['type' => 'order', 'id' => $row->order_id !== null ? (int) $row->order_id : null, 'name' => $row->order_customer_name];
    }

    public static function ageBucket(?int $days): string
    {
        if ($days === null) {
            return 'unknown';
        }

        return match (true) {
            $days <= 7 => '0-7',
            $days <= 30 => '8-30',
            $days <= 60 => '31-60',
            $days <= 90 => '61-90',
            default => '90+',
        };
    }

    private function verificationStatus(object $row): string
    {
        if ($row->status === 'succeeded') {
            return $row->verified_at ? 'verified' : 'unverified';
        }

        return $row->status === 'pending' ? 'pending' : 'not_applicable';
    }
}
