<?php

namespace App\Services\Reporting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Report D — Accounts Receivable.
 *
 * Invoice balances come from the commercial-document and allocation records,
 * never from an order's payment status. Credit-note effects arrive through the
 * credit ledger application entries the 4C credit-note service writes, so they
 * are never subtracted twice.
 */
final class ReceivableReportService
{
    public const BUCKETS = ['current', '1-30', '31-60', '61-90', '90+'];

    public const SETTLED_SQL = '(COALESCE(alloc.amount, 0) + COALESCE(apps.amount, 0))';

    public const OUTSTANDING_SQL = '(CASE WHEN (cd.total - '.self::SETTLED_SQL.') < 0 THEN 0 ELSE (cd.total - '.self::SETTLED_SQL.') END)';

    public const DUE_DATE_SQL = 'COALESCE(cd.expiry_date, cd.issue_date)';

    public function register(array $filters, ReportPeriod $period): LengthAwarePaginator
    {
        $query = $this->filtered($filters, $period);
        $asOf = $period->end->startOfDay();

        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $column = match ($filters['sort'] ?? '') {
            'outstanding' => self::OUTSTANDING_SQL,
            'due_date' => self::DUE_DATE_SQL,
            default => 'cd.issue_date',
        };

        return $query->orderByRaw($column.' '.$direction)->orderBy('cd.id')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (object $row) => $this->row($row, $asOf));
    }

    public function summary(array $filters, ReportPeriod $period): array
    {
        $asOf = $period->end->startOfDay();
        $cutoffs = [
            'current' => [$asOf->toDateString(), null],
            '1-30' => [$asOf->subDays(30)->toDateString(), $asOf->toDateString()],
            '31-60' => [$asOf->subDays(60)->toDateString(), $asOf->subDays(30)->toDateString()],
            '61-90' => [$asOf->subDays(90)->toDateString(), $asOf->subDays(60)->toDateString()],
            '90+' => [null, $asOf->subDays(90)->toDateString()],
        ];

        $buckets = [];
        $total = 0;
        $invoices = 0;

        foreach ($cutoffs as $label => [$newerThan, $olderThan]) {
            $query = $this->filtered(['currency' => $filters['currency'] ?? null], $period)
                ->whereRaw(self::OUTSTANDING_SQL.' > 0');

            if ($label === 'current') {
                $query->whereRaw(self::DUE_DATE_SQL.' >= ?', [$newerThan]);
            } else {
                if ($newerThan !== null) {
                    $query->whereRaw(self::DUE_DATE_SQL.' >= ?', [$newerThan]);
                }
                if ($olderThan !== null) {
                    $query->whereRaw(self::DUE_DATE_SQL.' < ?', [$olderThan]);
                }
            }

            $row = $query->select(DB::raw('COUNT(*) as invoices, SUM('.self::OUTSTANDING_SQL.') as outstanding'))->first();
            $amount = Money::minor($row->outstanding ?? 0);
            $buckets[$label] = ['count' => (int) ($row->invoices ?? 0), 'outstanding' => Money::decimal($amount)];
            $total += $amount;
            $invoices += (int) ($row->invoices ?? 0);
        }

        return [
            'as_of' => $asOf->toDateString(),
            'buckets' => $buckets,
            'invoices' => $invoices,
            'outstanding_total' => Money::decimal($total),
        ];
    }

    public function exportRows(array $filters, ReportPeriod $period): LazyCollection
    {
        $asOf = $period->end->startOfDay();

        return $this->filtered($filters, $period)->orderBy('cd.id')->cursor()
            ->map(fn (object $row) => $this->row($row, $asOf))
            ->take(50000);
    }

    private function baseQuery(): Builder
    {
        $allocations = DB::table('payment_allocations as pa')
            ->join('payment_allocation_operations as po', 'po.id', '=', 'pa.payment_allocation_operation_id')
            ->leftJoin('payment_allocation_reversals as pr', 'pr.payment_allocation_id', '=', 'pa.id')
            ->whereNull('pr.id')
            ->groupBy('pa.commercial_document_id')
            ->selectRaw('pa.commercial_document_id as did, SUM(pa.amount) as amount');

        $applications = DB::table('customer_credit_ledger_entries')
            ->where('entry_type', 'application')
            ->whereNotNull('commercial_document_id')
            ->groupBy('commercial_document_id')
            ->selectRaw("commercial_document_id as did, SUM(amount) as amount, SUM(CASE WHEN operation_reference LIKE 'cn:%' THEN amount ELSE 0 END) as credit_note_amount");

        $query = DB::table('commercial_documents as cd')
            ->leftJoin('customers as c', 'c.id', '=', 'cd.customer_id')
            ->leftJoin('organisations as org', 'org.id', '=', 'cd.organisation_id')
            ->leftJoinSub($allocations, 'alloc', 'alloc.did', '=', 'cd.id')
            ->leftJoinSub($applications, 'apps', 'apps.did', '=', 'cd.id')
            ->where('cd.document_type', 'invoice')
            // An invoice past its due date is marked expired by the commercial-document
            // service but remains an outstanding receivable; voided and draft invoices do not.
            ->whereIn('cd.status', ['issued', 'expired'])
            ->whereNull('cd.voided_at')
            ->select([
                'cd.id', 'cd.document_number', 'cd.issue_date', 'cd.expiry_date', 'cd.currency', 'cd.total',
                'cd.status', 'cd.customer_id', 'cd.organisation_id',
                'c.name as customer_name', 'org.name as organisation_name',
                'alloc.amount as allocated_amount', 'apps.amount as applied_amount',
                'apps.credit_note_amount as credit_note_amount',
            ]);

        return $query;
    }

    /** Outstanding receivable for a currency as of a date, across all issued invoices. */
    public function outstandingTotal(string $currency, \Carbon\CarbonImmutable $asOf): int
    {
        $row = $this->baseQuery()
            ->where('cd.currency', $currency)
            ->whereRaw('cd.issue_date <= ?', [$asOf->toDateString().' 23:59:59'])
            ->whereRaw(self::OUTSTANDING_SQL.' > 0')
            ->select(DB::raw('SUM('.self::OUTSTANDING_SQL.') as outstanding'))
            ->first();

        return Money::minor($row->outstanding ?? 0);
    }

    public function reconciliationCounts(array $filters, ReportPeriod $period): array
    {
        $row = $this->filtered($filters, $period)->select(DB::raw(
            'COUNT(*) as invoices, '
            .'SUM(cd.total) as invoice_total, '
            .'SUM(COALESCE(alloc.amount, 0)) as allocated_amount, '
            .'SUM(COALESCE(apps.amount, 0)) as credit_applied_amount, '
            .'SUM('.self::OUTSTANDING_SQL.') as outstanding_amount, '
            .'SUM(CASE WHEN '.self::SETTLED_SQL.' = 0 THEN 1 ELSE 0 END) as unpaid, '
            .'SUM(CASE WHEN '.self::SETTLED_SQL.' > 0 AND '.self::SETTLED_SQL.' < cd.total THEN 1 ELSE 0 END) as partially_paid, '
            .'SUM(CASE WHEN '.self::SETTLED_SQL.' >= cd.total THEN 1 ELSE 0 END) as fully_paid, '
            .'SUM(CASE WHEN '.self::SETTLED_SQL.' > cd.total THEN 1 ELSE 0 END) as over_settled'
        ))->first();

        return [
            'invoices' => (int) ($row->invoices ?? 0),
            'invoice_total' => Money::decimal(Money::minor($row->invoice_total ?? 0)),
            'allocated_amount' => Money::decimal(Money::minor($row->allocated_amount ?? 0)),
            'credit_applied_amount' => Money::decimal(Money::minor($row->credit_applied_amount ?? 0)),
            'outstanding_amount' => Money::decimal(Money::minor($row->outstanding_amount ?? 0)),
            'unpaid' => (int) ($row->unpaid ?? 0),
            'partially_paid' => (int) ($row->partially_paid ?? 0),
            'fully_paid' => (int) ($row->fully_paid ?? 0),
            'over_settled' => (int) ($row->over_settled ?? 0),
        ];
    }

    private function filtered(array $filters, ReportPeriod $period): Builder
    {
        $query = $this->baseQuery();
        // DATE columns are compared against full-day bounds so a same-day invoice
        // is never excluded by a "2026-11-28" vs "2026-11-28 00:00:00" mismatch.
        $query->whereBetween('cd.issue_date', [$period->from.' 00:00:00', $period->to.' 23:59:59']);

        if (filled($filters['currency'] ?? null)) {
            $query->where('cd.currency', $filters['currency']);
        }
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn (Builder $search) => $search
                ->where('cd.document_number', 'like', $term)
                ->orWhere('c.name', 'like', $term)
                ->orWhere('org.name', 'like', $term));
        }

        $state = $filters['payment_state'] ?? null;
        if ($state === 'unpaid') {
            $query->whereRaw(self::SETTLED_SQL.' = 0');
        } elseif ($state === 'fully_paid') {
            $query->whereRaw(self::SETTLED_SQL.' >= cd.total');
        } elseif ($state === 'partially_paid') {
            $query->whereRaw(self::SETTLED_SQL.' > 0 AND '.self::SETTLED_SQL.' < cd.total');
        }

        if (! empty($filters['overdue_only'])) {
            $query->whereRaw(self::DUE_DATE_SQL.' < ?', [$period->end->startOfDay()->toDateString()])
                ->whereRaw(self::OUTSTANDING_SQL.' > 0');
        }

        return $query;
    }

    private function row(object $row, \Carbon\CarbonImmutable $asOf): array
    {
        $total = Money::minor($row->total);
        $allocated = Money::minor($row->allocated_amount);
        $creditApplied = Money::minor($row->applied_amount);
        $creditNoteApplied = Money::minor($row->credit_note_amount);
        $settled = $allocated + $creditApplied;
        $outstanding = max(0, $total - $settled);

        $dueDate = $row->expiry_date ?: $row->issue_date;
        $daysOverdue = $dueDate
            ? max(0, (int) \Carbon\CarbonImmutable::parse($dueDate, config('app.timezone'))->startOfDay()->diffInDays($asOf->startOfDay(), false))
            : 0;

        return [
            'id' => (int) $row->id,
            'invoice_reference' => $row->document_number,
            'payer' => $row->organisation_id !== null
                ? ['type' => 'organisation', 'id' => (int) $row->organisation_id, 'name' => $row->organisation_name]
                : ['type' => 'customer', 'id' => $row->customer_id !== null ? (int) $row->customer_id : null, 'name' => $row->customer_name],
            'invoice_date' => $row->issue_date,
            'due_date' => $dueDate,
            'currency' => $row->currency,
            'invoice_total' => Money::decimal($total),
            'allocated_amount' => Money::decimal($allocated),
            'credit_note_applied_amount' => Money::decimal($creditNoteApplied),
            'credit_applied_amount' => Money::decimal(max(0, $creditApplied - $creditNoteApplied)),
            'settled_amount' => Money::decimal($settled),
            'outstanding_amount' => Money::decimal($outstanding),
            'payment_state' => $settled === 0 ? 'unpaid' : ($outstanding === 0 ? 'fully_paid' : 'partially_paid'),
            'days_overdue' => $daysOverdue,
            'ageing_bucket' => match (true) {
                $daysOverdue <= 0 => 'current',
                $daysOverdue <= 30 => '1-30',
                $daysOverdue <= 60 => '31-60',
                $daysOverdue <= 90 => '61-90',
                default => '90+',
            },
            'status' => $row->status,
        ];
    }
}
