<?php

namespace App\Services\Reporting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Report H — Credit Note Report.
 *
 * The note's remaining amount comes from FIFO attribution of the credit entry
 * the 4C credit-note service wrote (decision D6's conservative default), so it
 * always agrees with the credit ledger rather than a second attribution record.
 */
final class CreditNoteReportService
{
    public function __construct(private readonly CreditAttribution $attribution) {}

    public function register(array $filters, ReportPeriod $period): LengthAwarePaginator
    {
        $query = $this->filtered($filters, $period);

        $rows = $query->orderByRaw('COALESCE(cn.issued_at, cn.created_at) DESC')->orderBy('cn.id')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)));

        $remaining = $this->remainingFor($rows->getCollection());
        $applied = $this->appliedToOrigin($rows->getCollection());

        return $rows->through(function (object $row) use ($remaining, $applied) {
            return $this->row($row, $remaining, $applied);
        });
    }

    public function summary(array $filters, ReportPeriod $period): array
    {
        $row = $this->filtered($filters, $period)->select(DB::raw(
            "SUM(CASE WHEN cn.status = 'issued' THEN cn.total ELSE 0 END) as issued_amount, "
            ."SUM(CASE WHEN cn.status = 'issued' THEN 1 ELSE 0 END) as issued_count, "
            ."SUM(CASE WHEN cn.status = 'voided' THEN cn.total ELSE 0 END) as voided_amount, "
            ."SUM(CASE WHEN cn.status = 'voided' THEN 1 ELSE 0 END) as voided_count"
        ))->first();

        return [
            'issued_amount' => Money::decimal(Money::minor($row->issued_amount ?? 0)),
            'issued_count' => (int) ($row->issued_count ?? 0),
            'voided_amount' => Money::decimal(Money::minor($row->voided_amount ?? 0)),
            'voided_count' => (int) ($row->voided_count ?? 0),
        ];
    }

    public function exportRows(array $filters, ReportPeriod $period): LazyCollection
    {
        $cache = [];
        [$from, $to] = $period->bounds();
        $applied = DB::table('customer_credit_ledger_entries')
            ->where('entry_type', 'application')
            ->where('operation_reference', 'like', 'cn:%:apply')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('operation_reference, SUM(amount) as amount')
            ->groupBy('operation_reference')
            ->pluck('amount', 'operation_reference')->all();

        return $this->filtered($filters, $period)->orderBy('cn.id')->cursor()
            ->map(function (object $row) use (&$cache, $applied) {
                if ($row->credit_entry_id === null) {
                    return $this->row($row, [], $applied);
                }

                $payerType = $row->organisation_id !== null ? 'organisation' : 'customer';
                $payerId = (int) ($row->organisation_id ?? $row->customer_id);
                $key = $payerType.':'.$payerId.':'.$row->currency;
                $cache[$key] ??= $this->attribution->remainingByEntry($payerType, $payerId, $row->currency);

                return $this->row($row, $cache[$key], $applied);
            })->take(50000);
    }

    private function filtered(array $filters, ReportPeriod $period): Builder
    {
        $creditEntries = DB::table('customer_credit_ledger_entries')
            ->where('entry_type', 'credit_note_credit')
            ->whereNotNull('commercial_document_id')
            ->groupBy('commercial_document_id')
            ->selectRaw('commercial_document_id as did, MIN(id) as entry_id, SUM(amount) as amount');

        $query = DB::table('commercial_documents as cn')
            ->leftJoin('commercial_documents as inv', 'inv.id', '=', 'cn.related_document_id')
            ->leftJoin('customers as c', 'c.id', '=', 'cn.customer_id')
            ->leftJoin('organisations as org', 'org.id', '=', 'cn.organisation_id')
            ->leftJoin('users as issuer', 'issuer.id', '=', 'cn.issued_by')
            ->leftJoinSub($creditEntries, 'entry', 'entry.did', '=', 'cn.id')
            ->where('cn.document_type', 'credit_note')
            ->select([
                'cn.id', 'cn.document_number', 'cn.status', 'cn.total', 'cn.currency', 'cn.notes',
                'cn.issue_date', 'cn.issued_at', 'cn.created_at', 'cn.voided_at', 'cn.void_reason',
                'cn.customer_id', 'cn.organisation_id', 'inv.document_number as invoice_number',
                'inv.total as invoice_total',
                'c.name as customer_name', 'org.name as organisation_name', 'issuer.name as issuer_name',
                'entry.entry_id as credit_entry_id', 'entry.amount as credit_entry_amount',
            ]);

        $basis = ($filters['basis'] ?? 'issued') === 'created' ? 'cn.created_at' : 'cn.issued_at';
        [$from, $to] = $period->bounds();
        $query->whereBetween($basis, [$from, $to]);

        if (filled($filters['currency'] ?? null)) {
            $query->where('cn.currency', $filters['currency']);
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('cn.status', $filters['status']);
        }
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn (Builder $search) => $search
                ->where('cn.document_number', 'like', $term)
                ->orWhere('inv.document_number', 'like', $term)
                ->orWhere('c.name', 'like', $term)
                ->orWhere('org.name', 'like', $term));
        }

        return $query;
    }

    /** @param \Illuminate\Support\Collection<int, object> $rows */
    private function remainingFor($rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if ($row->credit_entry_id === null) {
                continue;
            }
            $payerType = $row->organisation_id !== null ? 'organisation' : 'customer';
            $payerId = (int) ($row->organisation_id ?? $row->customer_id);
            $groups[$payerType.':'.$payerId.':'.$row->currency] = [$payerType, $payerId, $row->currency];
        }

        $remaining = [];
        foreach ($groups as [$payerType, $payerId, $currency]) {
            $remaining += $this->attribution->remainingByEntry($payerType, $payerId, $currency);
        }

        return $remaining;
    }

    /** @param \Illuminate\Support\Collection<int, object> $rows */
    private function appliedToOrigin($rows): array
    {
        $references = [];
        foreach ($rows as $row) {
            if ($row->document_number) {
                $references[] = 'cn:'.$row->document_number.':apply';
            }
        }
        if ($references === []) {
            return [];
        }

        return DB::table('customer_credit_ledger_entries')
            ->where('entry_type', 'application')
            ->whereIn('operation_reference', $references)
            ->selectRaw('operation_reference, SUM(amount) as amount')
            ->groupBy('operation_reference')
            ->pluck('amount', 'operation_reference')->all();
    }

    private function row(object $row, array $remaining, array $applied = []): array
    {
        $total = Money::minor($row->total);
        $left = $row->credit_entry_id !== null ? ($remaining[(int) $row->credit_entry_id] ?? 0) : 0;
        // Convention-derived (metric M-85): the credit-note service records its
        // auto-application as `cn:<number>:apply`. Pooled FIFO attribution can
        // leave the note's own credit entry unconsumed while older credit is
        // consumed, so both figures are reported and labelled.
        $appliedToOrigin = Money::minor($applied['cn:'.$row->document_number.':apply'] ?? 0);

        $application = 'unapplied';
        if ($row->status === 'voided') {
            $application = 'voided';
        } elseif ($appliedToOrigin >= $total) {
            $application = 'applied';
        } elseif ($appliedToOrigin > 0) {
            $application = 'partially_applied';
        } elseif ($row->credit_entry_id !== null) {
            $application = match (true) {
                $left <= 0 => 'applied',
                $left < $total => 'partially_applied',
                default => 'unapplied',
            };
        }

        return [
            'id' => (int) $row->id,
            'credit_note_number' => $row->document_number,
            'original_invoice' => $row->invoice_number,
            'original_invoice_total' => $row->invoice_total !== null ? Money::decimal(Money::minor($row->invoice_total)) : null,
            'payer' => $row->organisation_id !== null
                ? ['type' => 'organisation', 'id' => (int) $row->organisation_id, 'name' => $row->organisation_name]
                : ['type' => 'customer', 'id' => $row->customer_id !== null ? (int) $row->customer_id : null, 'name' => $row->customer_name],
            'reason' => $row->notes,
            'amount' => Money::decimal($total),
            'applied_to_origin_amount' => Money::decimal($appliedToOrigin),
            'remaining_amount' => Money::decimal($left),
            'attribution_basis' => 'remaining is FIFO attribution of the note credit entry; applied_to_origin is the credit-note service auto-application',
            'currency' => $row->currency,
            'issued_at' => $row->issued_at,
            'issue_date' => $row->issue_date,
            'issuing_staff' => $row->issuer_name,
            'status' => $row->status,
            'application_status' => $application,
            'voided_at' => $row->voided_at,
            'void_reason' => $row->void_reason,
        ];
    }
}
