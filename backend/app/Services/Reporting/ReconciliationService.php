<?php

namespace App\Services\Reporting;

use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Internal reconciliation and auditable reconciliation history.
 *
 * Settlement reconciliation is deliberately reported as unavailable: no genuine
 * provider settlement feed exists (decision D4 pending), so no batch, fee or
 * payout figure is ever fabricated.
 */
final class ReconciliationService
{
    public const CLOSED_STATUSES = ['reconciled', 'reconciled_with_exceptions'];

    public function __construct(
        private readonly ReceiptLedger $ledger,
        private readonly ReceivableReportService $receivables,
        private readonly AuditService $audit,
    ) {}

    public function snapshot(array $filters, ReportPeriod $period): array
    {
        $receipts = $this->receiptCounts($filters, $period);
        $invoices = $this->receivables->reconciliationCounts($filters, $period);
        $exceptions = $this->exceptions($receipts, $invoices, $filters, $period);

        return [
            'period' => $period->meta('receipt date for receipts; invoice issue date for invoices'),
            'currency' => $filters['currency'] ?? null,
            'receipts' => $receipts,
            'invoices' => $invoices,
            'exceptions' => $exceptions,
            'settlements' => $this->settlementAvailability(),
        ];
    }

    public function receiptCounts(array $filters, ReportPeriod $period): array
    {
        $settled = '(COALESCE(alloc.amount, 0) + COALESCE(design.amount, 0) - COALESCE(design_rev.amount, 0))';
        $available = ReceiptLedger::AVAILABLE_SQL;

        $row = $this->ledger->applyFilters(
            $this->ledger->baseQuery(),
            array_merge($filters, ['status' => 'succeeded', 'purpose' => 'invoice']),
            $period,
        )->whereNotNull('pt.received_at')->select(DB::raw(
            "COUNT(*) as receipts, "
            ."SUM(pt.amount) as receipt_amount, "
            ."SUM({$settled}) as applied_amount, "
            ."SUM(CASE WHEN {$available} > 0 THEN {$available} ELSE 0 END) as available_amount, "
            ."SUM(CASE WHEN {$settled} = 0 THEN 1 ELSE 0 END) as unallocated, "
            ."SUM(CASE WHEN {$settled} > 0 AND {$available} = 0 THEN 1 ELSE 0 END) as fully_allocated, "
            ."SUM(CASE WHEN {$settled} > 0 AND {$available} > 0 THEN 1 ELSE 0 END) as partially_allocated, "
            ."SUM(CASE WHEN {$available} < 0 THEN 1 ELSE 0 END) as inconsistent"
        ))->first();

        [$from, $to] = $period->bounds();
        $reversals = DB::table('payment_allocation_reversals as pr')
            ->join('payment_allocations as pa', 'pa.id', '=', 'pr.payment_allocation_id')
            ->whereBetween('pr.reversed_at', [$from, $to])
            ->when(filled($filters['currency'] ?? null), fn ($query) => $query->where('pa.currency', $filters['currency']))
            ->selectRaw('COUNT(*) as count, SUM(pa.amount) as amount')->first();

        return [
            'receipts' => (int) ($row->receipts ?? 0),
            'receipt_amount' => Money::decimal(Money::minor($row->receipt_amount ?? 0)),
            'applied_amount' => Money::decimal(Money::minor($row->applied_amount ?? 0)),
            'available_amount' => Money::decimal(Money::minor($row->available_amount ?? 0)),
            'fully_allocated' => (int) ($row->fully_allocated ?? 0),
            'partially_allocated' => (int) ($row->partially_allocated ?? 0),
            'unallocated' => (int) ($row->unallocated ?? 0),
            'inconsistent' => (int) ($row->inconsistent ?? 0),
            'reversed_allocations' => (int) ($reversals->count ?? 0),
            'reversed_amount' => Money::decimal(Money::minor($reversals->amount ?? 0)),
        ];
    }

    public function exceptions(array $receipts, array $invoices, array $filters, ReportPeriod $period): array
    {
        $unresolvedRefunds = DB::table('refund_requests')
            ->where('status', 'submitting')
            ->when(filled($filters['currency'] ?? null), fn ($query) => $query->where('currency', $filters['currency']))
            ->count();

        $missingReceivedAt = DB::table('payment_transactions')
            ->where('status', 'succeeded')->whereNull('received_at')
            ->when(filled($filters['currency'] ?? null), fn ($query) => $query->where('currency', $filters['currency']))
            ->count();

        $items = [
            ['code' => 'E-01', 'label' => 'Receipts with a negative available balance', 'count' => $receipts['inconsistent']],
            ['code' => 'E-05', 'label' => 'Refunds awaiting a provider outcome', 'count' => $unresolvedRefunds],
            ['code' => 'E-07', 'label' => 'Invoices settled beyond their total', 'count' => $invoices['over_settled']],
            ['code' => 'E-08', 'label' => 'Successful receipts without a received date', 'count' => $missingReceivedAt],
        ];

        return array_merge($items, [['code' => 'TOTAL', 'label' => 'Total exceptions', 'count' => array_sum(array_column($items, 'count'))]]);
    }

    public function settlementAvailability(): array
    {
        return [
            'available' => false,
            'tier' => 'not_configured',
            'reason' => 'No genuine provider settlement feed is configured. Payment verification confirms that a provider captured a payment; it does not report settlement batches, provider fees or bank deposits.',
            'required_data' => [
                'settlement batch reference', 'provider', 'currency', 'settlement date',
                'gross amount', 'provider fees', 'net settlement amount', 'matched transactions',
            ],
            'decisions_pending' => ['D4', 'D5', 'D12', 'D13'],
        ];
    }

    public function prepare(User $actor, array $filters, ReportPeriod $period): array
    {
        $snapshot = $this->snapshot($filters, $period);
        $exceptionCount = (int) collect($snapshot['exceptions'])->where('code', 'TOTAL')->sum('count');
        $reference = 'REC-'.$period->from.'-'.$period->to.'-'.Str::upper(Str::random(6));

        $id = DB::table('reconciliation_runs')->insertGetId([
            'reference' => $reference,
            'source' => 'internal_combined',
            'period_start' => $period->from,
            'period_end' => $period->to,
            'status' => 'awaiting_review',
            'prepared_by' => $actor->id,
            'prepared_by_name_snapshot' => $actor->name,
            'prepared_at' => now(),
            'exception_count' => $exceptionCount,
            'summary' => json_encode($snapshot),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->log($actor, 'financials.reconciliation.prepared', "Prepared reconciliation {$reference} for {$period->from} to {$period->to}.", null, null, ['reference' => $reference, 'exception_count' => $exceptionCount]);

        return $this->run($id);
    }

    public function review(User $actor, int $id, string $status, ?string $notes): array
    {
        if (! in_array($status, self::CLOSED_STATUSES, true)) {
            throw new DomainException('A reconciliation can only be closed as reconciled or reconciled with exceptions.');
        }

        $run = DB::table('reconciliation_runs')->where('id', $id)->first();
        if (! $run) {
            throw new DomainException('Reconciliation run not found.');
        }
        if (in_array($run->status, self::CLOSED_STATUSES, true)) {
            throw new DomainException('This reconciliation is already closed and cannot be edited.');
        }
        if ($status === 'reconciled' && (int) $run->exception_count > 0) {
            throw new DomainException('This run records '.$run->exception_count.' exception(s); close it as reconciled with exceptions instead.');
        }

        DB::table('reconciliation_runs')->where('id', $id)->update([
            'status' => $status,
            'reviewed_by' => $actor->id,
            'reviewed_by_name_snapshot' => $actor->name,
            'reviewed_at' => now(),
            'resolution_notes' => $notes,
            'updated_at' => now(),
        ]);

        $this->audit->log($actor, 'financials.reconciliation.reviewed', "Closed reconciliation {$run->reference} as {$status}.", null, null, ['reference' => $run->reference, 'status' => $status]);

        return $this->run($id);
    }

    public function runs(array $filters): LengthAwarePaginator
    {
        $query = DB::table('reconciliation_runs')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['source'] ?? null), fn ($q) => $q->where('source', $filters['source']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where('period_end', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where('period_start', '<=', $filters['to']))
            ->orderByDesc('prepared_at')->orderByDesc('id');

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 20), 100)))
            ->through(fn (object $row) => $this->runRow($row));
    }

    public function run(int $id): array
    {
        $row = DB::table('reconciliation_runs')->where('id', $id)->first();
        if (! $row) {
            throw new DomainException('Reconciliation run not found.');
        }

        return $this->runRow($row) + ['summary' => json_decode((string) $row->summary, true)];
    }

    private function runRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'reference' => $row->reference,
            'source' => $row->source,
            'period_start' => $row->period_start,
            'period_end' => $row->period_end,
            'status' => $row->status,
            'prepared_by' => $row->prepared_by_name_snapshot,
            'prepared_at' => $row->prepared_at,
            'reviewed_by' => $row->reviewed_by_name_snapshot,
            'reviewed_at' => $row->reviewed_at,
            'exception_count' => (int) $row->exception_count,
            'resolution_notes' => $row->resolution_notes,
            'immutable' => in_array($row->status, self::CLOSED_STATUSES, true),
        ];
    }
}
