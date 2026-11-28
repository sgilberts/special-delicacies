<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stage 4D financial management dashboard.
 *
 * Every figure is an aggregate over the authoritative Stage 4A–4C records for a
 * single currency. Receipts, allocations, credit and refunds stay separate
 * concepts; nothing is added into one misleading total.
 */
final class DashboardService
{
    public function __construct(
        private readonly ReceiptLedger $ledger,
        private readonly ReceiptRegisterService $receiptRegister,
        private readonly AllocationReportService $allocationReport,
        private readonly UnallocatedReportService $unallocatedReport,
        private readonly ReceivableReportService $receivables,
        private readonly RefundReportService $refunds,
        private readonly CreditNoteReportService $creditNotes,
    ) {}

    public function payload(array $filters, ReportPeriod $period): array
    {
        $currencies = $this->currencies();
        $currency = (string) ($filters['currency'] ?? ($currencies[0] ?? 'GHS'));
        if (! in_array($currency, $currencies, true)) {
            $currencies[] = $currency;
        }

        $overview = $this->overview($period, $currency);
        $previous = $this->overview($period->previous(), $currency);

        return [
            'period' => $period->meta('received_at'),
            'currency' => $currency,
            'currencies' => array_values($currencies),
            'overview' => array_merge($overview, [
                'previous' => $previous,
                'definitions' => $this->definitions(),
            ]),
            'trends' => $this->trends($period, $currency),
            'operational' => $this->operational($period, $currency),
        ];
    }

    public function overview(ReportPeriod $period, string $currency): array
    {
        [$from, $to] = $period->bounds();

        $receipts = DB::table('payment_transactions')
            ->where('status', 'succeeded')
            ->whereNotNull('received_at')
            ->where('currency', $currency)
            ->whereBetween('received_at', [$from, $to])
            ->selectRaw("COUNT(*) as receipt_count, SUM(amount) as total, SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash, SUM(CASE WHEN payment_method <> 'cash' THEN amount ELSE 0 END) as online")
            ->first();

        $allocated = DB::table('payment_allocations as pa')
            ->join('payment_allocation_operations as po', 'po.id', '=', 'pa.payment_allocation_operation_id')
            ->leftJoin('payment_allocation_reversals as pr', 'pr.payment_allocation_id', '=', 'pa.id')
            ->whereNull('pr.id')
            ->where('pa.currency', $currency)
            ->whereBetween('pa.allocated_at', [$from, $to])
            ->sum('pa.amount');

        $unallocated = $this->ledger->aggregate(
            ['status' => 'succeeded', 'currency' => $currency, 'purpose' => 'invoice'],
            $period,
            'SUM(CASE WHEN '.ReceiptLedger::AVAILABLE_SQL.' > 0 THEN '.ReceiptLedger::AVAILABLE_SQL.' ELSE 0 END) as available',
        )->first();

        $invoices = DB::table('commercial_documents')
            ->where('document_type', 'invoice')
            ->whereNull('voided_at')
            ->where('currency', $currency)
            ->whereBetween('issued_at', [$from, $to])
            ->sum('total');

        $customerCredit = $this->creditBalance('customer_id', $currency, $to);
        $organisationCredit = $this->creditBalance('organisation_id', $currency, $to);

        $refundsPending = DB::table('refund_requests')
            ->whereIn('status', ['requested', 'approved', 'submitting', 'submitted'])
            ->where('currency', $currency)
            ->whereBetween('requested_at', [$from, $to])
            ->sum('amount');

        $refundsCompleted = DB::table('refund_requests')
            ->where('status', 'confirmed')
            ->where('currency', $currency)
            ->whereBetween('confirmed_at', [$from, $to])
            ->sum('amount');

        $creditNotesIssued = DB::table('commercial_documents')
            ->where('document_type', 'credit_note')
            ->where('status', 'issued')
            ->where('currency', $currency)
            ->whereBetween('issued_at', [$from, $to])
            ->sum('total');

        return [
            'receipts_total' => Money::decimal(Money::minor($receipts->total ?? 0)),
            'receipt_count' => (int) ($receipts->receipt_count ?? 0),
            'cash_receipts' => Money::decimal(Money::minor($receipts->cash ?? 0)),
            'online_receipts' => Money::decimal(Money::minor($receipts->online ?? 0)),
            'allocated_total' => Money::decimal(Money::minor($allocated ?? 0)),
            'unallocated_total' => Money::decimal(Money::minor($unallocated->available ?? 0)),
            'invoice_value_total' => Money::decimal(Money::minor($invoices ?? 0)),
            'receivables_outstanding' => Money::decimal($this->receivables->outstandingTotal($currency, $period->end)),
            'customer_credit_balance' => Money::decimal($customerCredit),
            'organisation_credit_balance' => Money::decimal($organisationCredit),
            'refunds_pending' => Money::decimal(Money::minor($refundsPending ?? 0)),
            'refunds_completed' => Money::decimal(Money::minor($refundsCompleted ?? 0)),
            'credit_notes_issued' => Money::decimal(Money::minor($creditNotesIssued ?? 0)),
        ];
    }

    public function trends(ReportPeriod $period, string $currency): array
    {
        [$from, $to] = $period->bounds();

        $days = [];
        for ($cursor = $period->start->startOfDay(); $cursor->lessThanOrEqualTo($period->end->startOfDay()); $cursor = $cursor->addDay()) {
            $days[$cursor->toDateString()] = [
                'date' => $cursor->toDateString(),
                'cash_receipts' => 0, 'online_receipts' => 0, 'receipts_total' => 0,
                'invoice_receipts' => 0, 'allocated' => 0, 'invoices_issued' => 0, 'refunds_confirmed' => 0,
                'credit_notes_issued' => 0, 'unallocated_movement' => 0,
            ];
        }

        $add = function (string $day, string $key, int $minor) use (&$days): void {
            if (isset($days[$day])) {
                $days[$day][$key] += $minor;
            }
        };

        DB::table('payment_transactions')
            ->where('status', 'succeeded')->whereNotNull('received_at')->where('currency', $currency)
            ->whereBetween('received_at', [$from, $to])
            ->selectRaw("DATE(received_at) as day, SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash, SUM(CASE WHEN payment_method <> 'cash' THEN amount ELSE 0 END) as online, SUM(amount) as total, SUM(CASE WHEN payment_purpose = 'invoice' THEN amount ELSE 0 END) as invoice_total")
            ->groupBy('day')->get()
            ->each(function (object $row) use ($add): void {
                $add($row->day, 'cash_receipts', Money::minor($row->cash));
                $add($row->day, 'online_receipts', Money::minor($row->online));
                $add($row->day, 'receipts_total', Money::minor($row->total));
                $add($row->day, 'invoice_receipts', Money::minor($row->invoice_total));
            });

        DB::table('payment_allocations as pa')
            ->join('payment_allocation_operations as po', 'po.id', '=', 'pa.payment_allocation_operation_id')
            ->leftJoin('payment_allocation_reversals as pr', 'pr.payment_allocation_id', '=', 'pa.id')
            ->whereNull('pr.id')->where('pa.currency', $currency)->whereBetween('pa.allocated_at', [$from, $to])
            ->selectRaw('DATE(pa.allocated_at) as day, SUM(pa.amount) as total')->groupBy('day')->get()
            ->each(fn (object $row) => $add($row->day, 'allocated', Money::minor($row->total)));

        DB::table('commercial_documents')
            ->where('document_type', 'invoice')->whereNull('voided_at')->where('currency', $currency)
            ->whereBetween('issued_at', [$from, $to])
            ->selectRaw('DATE(issued_at) as day, SUM(total) as total')->groupBy('day')->get()
            ->each(fn (object $row) => $add($row->day, 'invoices_issued', Money::minor($row->total)));

        DB::table('refund_requests')
            ->where('status', 'confirmed')->where('currency', $currency)->whereBetween('confirmed_at', [$from, $to])
            ->selectRaw('DATE(confirmed_at) as day, SUM(amount) as total')->groupBy('day')->get()
            ->each(fn (object $row) => $add($row->day, 'refunds_confirmed', Money::minor($row->total)));

        DB::table('commercial_documents')
            ->where('document_type', 'credit_note')->where('status', 'issued')->where('currency', $currency)
            ->whereBetween('issued_at', [$from, $to])
            ->selectRaw('DATE(issued_at) as day, SUM(total) as total')->groupBy('day')->get()
            ->each(fn (object $row) => $add($row->day, 'credit_notes_issued', Money::minor($row->total)));

        DB::table('customer_credit_ledger_entries')
            ->where('entry_type', 'designation')->where('currency', $currency)->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as day, SUM(amount) as total')->groupBy('day')->get()
            ->each(fn (object $row) => $add($row->day, 'unallocated_movement', -Money::minor($row->total)));

        DB::table('customer_credit_ledger_entries as rev')
            ->join('customer_credit_ledger_entries as orig', 'orig.id', '=', 'rev.reverses_entry_id')
            ->where('orig.entry_type', 'designation')->where('rev.entry_type', 'reversal')
            ->where('rev.currency', $currency)->whereBetween('rev.created_at', [$from, $to])
            ->selectRaw('DATE(rev.created_at) as day, SUM(rev.amount) as total')->groupBy('day')->get()
            ->each(fn (object $row) => $add($row->day, 'unallocated_movement', Money::minor($row->total)));

        $daily = [];
        foreach ($days as $day) {
            $movement = $day['invoice_receipts'] - $day['allocated'] + $day['unallocated_movement'];
            $daily[] = [
                'date' => $day['date'],
                'cash_receipts' => Money::decimal($day['cash_receipts']),
                'online_receipts' => Money::decimal($day['online_receipts']),
                'receipts_total' => Money::decimal($day['receipts_total']),
                'allocated' => Money::decimal($day['allocated']),
                'invoices_issued' => Money::decimal($day['invoices_issued']),
                'refunds_confirmed' => Money::decimal($day['refunds_confirmed']),
                'credit_notes_issued' => Money::decimal($day['credit_notes_issued']),
                'unallocated_movement' => Money::decimal($movement),
            ];
        }

        return [
            'daily' => $daily,
            'weekly' => $this->groupBy($daily, 'W'),
            'monthly' => $this->groupBy($daily, 'M'),
        ];
    }

    public function operational(ReportPeriod $period, string $currency): array
    {
        return [
            'recent_receipts' => $this->receiptRegister->register(['currency' => $currency, 'status' => 'succeeded', 'per_page' => 5], $period)->items(),
            'recent_allocations' => $this->allocationReport->register(['currency' => $currency, 'per_page' => 5], $period)->items(),
            'recent_refunds' => $this->refunds->register(['currency' => $currency, 'per_page' => 5], $period)->items(),
            'recent_credit_notes' => $this->creditNotes->register(['currency' => $currency, 'per_page' => 5], $period)->items(),
            'unallocated_receipts' => $this->unallocatedReport->register(['currency' => $currency, 'per_page' => 5, 'sort' => 'received_at', 'direction' => 'asc'], $period)->items(),
            'outstanding_invoices' => $this->receivables->register(['currency' => $currency, 'per_page' => 5, 'sort' => 'outstanding'], $period)->items(),
            'exceptions' => $this->exceptions($period, $currency),
        ];
    }

    public function exceptions(ReportPeriod $period, string $currency): array
    {
        [$from, $to] = $period->bounds();

        $negativeResidual = $this->ledger->aggregate(
            ['status' => 'succeeded', 'currency' => $currency],
            $period,
            'SUM(CASE WHEN '.ReceiptLedger::AVAILABLE_SQL.' < 0 THEN 1 ELSE 0 END) as count',
        )->first();

        $overSettled = DB::table('commercial_documents as cd')
            ->leftJoinSub(DB::table('payment_allocations as pa')
                ->join('payment_allocation_operations as po', 'po.id', '=', 'pa.payment_allocation_operation_id')
                ->leftJoin('payment_allocation_reversals as pr', 'pr.payment_allocation_id', '=', 'pa.id')
                ->whereNull('pr.id')->groupBy('pa.commercial_document_id')
                ->selectRaw('pa.commercial_document_id as did, SUM(pa.amount) as amount'), 'alloc', 'alloc.did', '=', 'cd.id')
            ->leftJoinSub(DB::table('customer_credit_ledger_entries')
                ->where('entry_type', 'application')->whereNotNull('commercial_document_id')
                ->groupBy('commercial_document_id')
                ->selectRaw('commercial_document_id as did, SUM(amount) as amount'), 'apps', 'apps.did', '=', 'cd.id')
            ->where('cd.document_type', 'invoice')->whereIn('cd.status', ['issued', 'expired'])->whereNull('cd.voided_at')
            ->where('cd.currency', $currency)->whereBetween('cd.issue_date', [$period->from.' 00:00:00', $period->to.' 23:59:59'])
            ->whereRaw(ReceivableReportService::SETTLED_SQL.' > cd.total')
            ->count();

        $missingReceivedAt = DB::table('payment_transactions')
            ->where('status', 'succeeded')->whereNull('received_at')->where('currency', $currency)->count();

        $unresolvedRefunds = DB::table('refund_requests')
            ->where('status', 'submitting')->where('currency', $currency)
            ->whereBetween('submitted_at', [$from, $to])->count();

        $exceptions = [
            ['code' => 'E-01', 'label' => 'Receipts with a negative available balance', 'count' => (int) ($negativeResidual->count ?? 0)],
            ['code' => 'E-05', 'label' => 'Refunds awaiting a provider outcome', 'count' => $unresolvedRefunds],
            ['code' => 'E-07', 'label' => 'Invoices settled beyond their total', 'count' => $overSettled],
            ['code' => 'E-08', 'label' => 'Successful receipts without a received date', 'count' => $missingReceivedAt],
        ];

        return array_merge($exceptions, [['code' => 'TOTAL', 'label' => 'Total exceptions', 'count' => array_sum(array_column($exceptions, 'count'))]]);
    }

    private function currencies(): array
    {
        $currencies = DB::table('payment_transactions')->where('status', 'succeeded')->distinct()->pluck('currency')
            ->merge(DB::table('commercial_documents')->where('document_type', 'invoice')->distinct()->pluck('currency'))
            ->filter()->unique()->sort()->values()->all();

        return $currencies === [] ? ['GHS'] : $currencies;
    }

    private function creditBalance(string $column, string $currency, string $until): int
    {
        $row = DB::table('customer_credit_ledger_entries')
            ->whereNotNull($column)->where('currency', $currency)->where('created_at', '<=', $until)
            ->selectRaw("SUM(CASE WHEN entry_type IN ('designation','credit_note_credit') THEN amount ELSE 0 END) as credited, SUM(CASE WHEN entry_type IN ('application','reversal') THEN amount ELSE 0 END) as debited")
            ->first();

        return Money::minor($row->credited ?? 0) - Money::minor($row->debited ?? 0);
    }

    private function groupBy(array $daily, string $mode): array
    {
        $groups = [];
        foreach ($daily as $day) {
            $date = CarbonImmutable::parse($day['date']);
            $key = $mode === 'W' ? $date->format('o-\WW') : $date->format('Y-m');
            $groups[$key] ??= ['period' => $key, 'cash_receipts' => 0, 'online_receipts' => 0, 'receipts_total' => 0, 'allocated' => 0, 'invoices_issued' => 0, 'refunds_confirmed' => 0, 'credit_notes_issued' => 0, 'unallocated_movement' => 0];
            foreach (['cash_receipts', 'online_receipts', 'receipts_total', 'allocated', 'invoices_issued', 'refunds_confirmed', 'credit_notes_issued', 'unallocated_movement'] as $metric) {
                $groups[$key][$metric] += Money::minor($day[$metric]);
            }
        }

        return array_values(array_map(function (array $group): array {
            foreach (['cash_receipts', 'online_receipts', 'receipts_total', 'allocated', 'invoices_issued', 'refunds_confirmed', 'credit_notes_issued', 'unallocated_movement'] as $metric) {
                $group[$metric] = Money::decimal($group[$metric]);
            }

            return $group;
        }, $groups));
    }

    private function definitions(): array
    {
        return [
            ['key' => 'receipts_total', 'label' => 'Successful receipts', 'basis' => 'Succeeded payment transactions with a received date, by received date. Pending, failed and cancelled money is excluded.'],
            ['key' => 'cash_receipts', 'label' => 'Cash receipts', 'basis' => 'Successful receipts collected by cash.'],
            ['key' => 'online_receipts', 'label' => 'Online receipts', 'basis' => 'Successful receipts collected through a payment provider.'],
            ['key' => 'allocated_total', 'label' => 'Allocated receipts', 'basis' => 'Active allocations to invoices in the period. Reversed allocations are excluded.'],
            ['key' => 'unallocated_total', 'label' => 'Unallocated receipts', 'basis' => 'Successful invoice receipts received in the period with money still not allocated or designated as credit. Order receipts are settled against their order and are not counted here.'],
            ['key' => 'invoice_value_total', 'label' => 'Invoice value issued', 'basis' => 'Invoices issued in the period. Invoice value is not cash revenue.'],
            ['key' => 'receivables_outstanding', 'label' => 'Outstanding receivables', 'basis' => 'Issued invoices minus active allocations and applied credit as of the period end.'],
            ['key' => 'customer_credit_balance', 'label' => 'Customer credit balance', 'basis' => 'Derived from the credit ledger: credits minus applications and reversals. Credit is not cash received.'],
            ['key' => 'organisation_credit_balance', 'label' => 'Organisation credit balance', 'basis' => 'Derived organisation credit ledger balance. Credit is not cash received.'],
            ['key' => 'refunds_pending', 'label' => 'Refunds pending', 'basis' => 'Requested, approved, submitting and submitted refunds. Not money returned yet.'],
            ['key' => 'refunds_completed', 'label' => 'Refunds completed', 'basis' => 'Confirmed refunds only, by confirmation date.'],
            ['key' => 'credit_notes_issued', 'label' => 'Credit notes issued', 'basis' => 'Issued credit notes by issue date. A credit note is not a cash refund.'],
        ];
    }
}
