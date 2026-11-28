<?php

namespace App\Services\Reporting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/** Report B — Payment Allocation Report. Active and reversed allocations stay distinct. */
final class AllocationReportService
{
    public const SORTABLE = ['allocated_at', 'amount'];

    public function __construct(private readonly ReceiptLedger $ledger) {}

    public function register(array $filters, ReportPeriod $period): LengthAwarePaginator
    {
        $query = $this->filtered($filters, $period);

        $sort = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'allocated_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $column = $sort === 'amount' ? 'pa.amount' : 'pa.allocated_at';

        return $query->orderByRaw($column.' '.$direction)->orderBy('pa.id')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (object $row) => $this->row($row));
    }

    public function summary(array $filters, ReportPeriod $period): array
    {
        $row = $this->filtered($filters, $period)->select(DB::raw(
            'SUM(CASE WHEN pr.id IS NULL THEN pa.amount ELSE 0 END) as active_amount, '
            .'SUM(CASE WHEN pr.id IS NOT NULL THEN pa.amount ELSE 0 END) as reversed_amount, '
            .'SUM(CASE WHEN pr.id IS NULL THEN 1 ELSE 0 END) as active_count, '
            .'SUM(CASE WHEN pr.id IS NOT NULL THEN 1 ELSE 0 END) as reversed_count'
        ))->first();

        return [
            'active_amount' => Money::decimal(Money::minor($row->active_amount ?? 0)),
            'reversed_amount' => Money::decimal(Money::minor($row->reversed_amount ?? 0)),
            'active_count' => (int) ($row->active_count ?? 0),
            'reversed_count' => (int) ($row->reversed_count ?? 0),
        ];
    }

    public function exportRows(array $filters, ReportPeriod $period): LazyCollection
    {
        return $this->filtered($filters, $period)->orderBy('pa.id')->cursor()
            ->map(fn (object $row) => $this->row($row))
            ->take(50000);
    }

    private function filtered(array $filters, ReportPeriod $period): Builder
    {
        $query = DB::table('payment_allocations as pa')
            ->join('payment_allocation_operations as po', 'po.id', '=', 'pa.payment_allocation_operation_id')
            ->join('payment_transactions as pt', 'pt.id', '=', 'po.payment_transaction_id')
            ->join('commercial_documents as cd', 'cd.id', '=', 'pa.commercial_document_id')
            ->leftJoin('payment_allocation_reversals as pr', 'pr.payment_allocation_id', '=', 'pa.id')
            ->leftJoin('users as actor', 'actor.id', '=', 'po.allocated_by')
            ->leftJoin('users as reversal_actor', 'reversal_actor.id', '=', 'pr.reversed_by')
            ->leftJoin('customers as c', 'c.id', '=', 'pt.customer_id')
            ->leftJoin('organisations as org', 'org.id', '=', 'pt.organisation_id')
            ->leftJoinSub($this->ledger->activeAllocationsSub(), 'receipt_alloc', 'receipt_alloc.pid', '=', 'pt.id')
            ->leftJoinSub($this->ledger->designationsSub(), 'receipt_design', 'receipt_design.pid', '=', 'pt.id')
            ->leftJoinSub($this->ledger->designationReversalsSub(), 'receipt_design_rev', 'receipt_design_rev.pid', '=', 'pt.id')
            ->select([
                'pa.id', 'pa.amount', 'pa.currency', 'pa.allocated_at',
                'po.operation_reference', 'po.allocated_at as operation_allocated_at',
                'pt.transaction_reference', 'pt.amount as receipt_amount', 'pt.currency as receipt_currency',
                'pt.customer_id', 'pt.organisation_id',
                'cd.id as invoice_id', 'cd.document_number',
                'c.name as customer_name', 'org.name as organisation_name',
                'actor.name as actor_name',
                'pr.id as reversal_id', 'pr.operation_reference as reversal_reference', 'pr.reversed_at',
                'pr.reason as reversal_reason', 'reversal_actor.name as reversal_actor_name',
                'receipt_alloc.amount as receipt_allocated_amount', 'receipt_design.amount as receipt_designated_amount',
                'receipt_design_rev.amount as receipt_designated_reversed_amount',
            ]);

        [$from, $to] = $period->bounds();
        if (($filters['basis'] ?? 'allocation') === 'receipt') {
            $query->whereRaw(ReceiptLedger::RECEIPT_DATE_SQL.' BETWEEN ? AND ?', [$from, $to]);
        } else {
            $query->whereBetween('pa.allocated_at', [$from, $to]);
        }

        if (filled($filters['currency'] ?? null)) {
            $query->where('pa.currency', $filters['currency']);
        }
        if (($filters['status'] ?? null) === 'active') {
            $query->whereNull('pr.id');
        } elseif (($filters['status'] ?? null) === 'reversed') {
            $query->whereNotNull('pr.id');
        }
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn (Builder $search) => $search
                ->where('po.operation_reference', 'like', $term)
                ->orWhere('pt.transaction_reference', 'like', $term)
                ->orWhere('cd.document_number', 'like', $term)
                ->orWhere('pr.operation_reference', 'like', $term)
                ->orWhere('c.name', 'like', $term)
                ->orWhere('org.name', 'like', $term));
        }

        return $query;
    }

    private function row(object $row): array
    {
        $receiptAmount = Money::minor($row->receipt_amount);
        $allocatedTotal = Money::minor($row->receipt_allocated_amount);
        $designated = max(0, Money::minor($row->receipt_designated_amount) - Money::minor($row->receipt_designated_reversed_amount));

        return [
            'id' => (int) $row->id,
            'allocation_reference' => $row->operation_reference,
            'receipt_reference' => $row->transaction_reference,
            'receipt_amount' => Money::decimal($receiptAmount),
            'receipt_allocated_amount' => Money::decimal($allocatedTotal),
            'receipt_available_amount' => Money::decimal(max(0, $receiptAmount - $allocatedTotal - $designated)),
            'allocation_amount' => Money::decimal(Money::minor($row->amount)),
            'invoice_reference' => $row->document_number,
            'invoice_id' => (int) $row->invoice_id,
            'payer' => $row->organisation_id !== null
                ? ['type' => 'organisation', 'id' => (int) $row->organisation_id, 'name' => $row->organisation_name]
                : ['type' => 'customer', 'id' => $row->customer_id !== null ? (int) $row->customer_id : null, 'name' => $row->customer_name],
            'currency' => $row->currency,
            'allocated_at' => $row->operation_allocated_at ?: $row->allocated_at,
            'actor' => $row->actor_name,
            'reversed' => $row->reversal_id !== null,
            'reversal' => $row->reversal_id === null ? null : [
                'reference' => $row->reversal_reference,
                'reversed_at' => $row->reversed_at,
                'reason' => $row->reversal_reason,
                'actor' => $row->reversal_actor_name,
            ],
        ];
    }
}
