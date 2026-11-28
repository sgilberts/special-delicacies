<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ActivityReportService;
use App\Services\Reporting\AllocationReportService;
use App\Services\Reporting\CreditNoteReportService;
use App\Services\Reporting\CreditReportService;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\DashboardService;
use App\Services\Reporting\ReceiptRegisterService;
use App\Services\Reporting\ReceivableReportService;
use App\Services\Reporting\RefundReportService;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\UnallocatedReportService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stage 4D read-only financial reporting endpoints.
 *
 * Every endpoint is permission-gated in routes/api.php, validates its filters
 * server-side, and produces its figures from the same reporting services the CSV
 * exports use.
 */
class FinancialReportsController extends Controller
{
    private ?array $currencyCache = null;

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ReceiptRegisterService $receipts,
        private readonly AllocationReportService $allocations,
        private readonly UnallocatedReportService $unallocated,
        private readonly ReceivableReportService $receivables,
        private readonly CreditReportService $credit,
        private readonly RefundReportService $refunds,
        private readonly CreditNoteReportService $creditNotes,
        private readonly ActivityReportService $activity,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $period = ReportPeriod::fromFilters($filters);

        return $this->envelope($this->dashboard->payload($filters, $period), 'received_at');
    }

    public function receipts(Request $request): JsonResponse
    {
        $filters = $this->filters($request, [
            'status' => ['nullable', 'in:pending,succeeded,failed,cancelled'],
            'method' => ['nullable', 'in:cash,gateway'],
            'provider' => ['nullable', 'string', 'max:40'],
            'purpose' => ['nullable', 'in:order,invoice'],
            'payer_type' => ['nullable', 'in:customer,organisation'],
        ]);
        $period = ReportPeriod::fromFilters($filters);
        $page = $this->receipts->register($filters, $period, $this->canSeeContacts($request, 'customer'));

        return $this->paged($page, $this->receipts->summary($filters, $period), 'received_at');
    }

    public function receiptsExport(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, [
            'status' => ['nullable', 'in:pending,succeeded,failed,cancelled'],
            'method' => ['nullable', 'in:cash,gateway'],
            'provider' => ['nullable', 'string', 'max:40'],
            'purpose' => ['nullable', 'in:order,invoice'],
            'payer_type' => ['nullable', 'in:customer,organisation'],
        ]);
        $period = ReportPeriod::fromFilters($filters);
        $withContacts = $this->canSeeContacts($request, 'customer');

        $rows = $this->receipts->exportRows($filters, $period, $withContacts)->map(fn (array $row) => [
            $row['transaction_reference'], $row['provider_reference'], $row['payer']['name'], $row['payer']['type'],
            $row['order_number'], $row['payment_purpose'], $row['payment_method'], $row['provider'], $row['currency'],
            $row['amount'], $row['status'], $row['verification_status'], $row['received_at'], $row['confirmed_by'],
            $row['allocated_amount'], $row['designated_amount'], $row['available_amount'], $row['refundable_amount'],
        ]);

        return CsvExporter::download($this->filename('receipts', $period), [
            'Transaction reference', 'Provider reference', 'Payer', 'Payer type', 'Order', 'Purpose', 'Method', 'Provider',
            'Currency', 'Amount', 'Status', 'Verification', 'Received at', 'Confirmed by', 'Allocated', 'Designated credit',
            'Available', 'Refundable',
        ], $rows);
    }

    public function allocations(Request $request): JsonResponse
    {
        $filters = $this->filters($request, [
            'status' => ['nullable', 'in:active,reversed,all'],
            'basis' => ['nullable', 'in:allocation,receipt'],
        ]);
        $period = ReportPeriod::fromFilters($filters);
        $page = $this->allocations->register($filters, $period);

        return $this->paged($page, $this->allocations->summary($filters, $period), 'allocated_at');
    }

    public function allocationsExport(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, [
            'status' => ['nullable', 'in:active,reversed,all'],
            'basis' => ['nullable', 'in:allocation,receipt'],
        ]);
        $period = ReportPeriod::fromFilters($filters);

        $rows = $this->allocations->exportRows($filters, $period)->map(fn (array $row) => [
            $row['receipt_reference'], $row['allocation_reference'], $row['receipt_amount'], $row['receipt_allocated_amount'],
            $row['receipt_available_amount'], $row['allocation_amount'], $row['invoice_reference'], $row['payer']['name'],
            $row['currency'], $row['allocated_at'], $row['actor'], $row['reversed'] ? 'reversed' : 'active',
            $row['reversal']['reference'] ?? '', $row['reversal']['reason'] ?? '',
        ]);

        return CsvExporter::download($this->filename('payment-allocations', $period), [
            'Receipt', 'Allocation reference', 'Receipt amount', 'Receipt allocated', 'Receipt available',
            'Allocation amount', 'Invoice', 'Payer', 'Currency', 'Allocated at', 'Actor', 'State',
            'Reversal reference', 'Reversal reason',
        ], $rows);
    }

    public function unallocated(Request $request): JsonResponse
    {
        $filters = $this->filters($request, ['bucket' => ['nullable', 'in:0-7,8-30,31-60,61-90,90+']]);
        $period = ReportPeriod::fromFilters($filters);
        $page = $this->unallocated->register($filters, $period, $this->canSeeContacts($request, 'customer'));

        return $this->paged($page, $this->unallocated->summary($filters, $period), 'received_at');
    }

    public function unallocatedExport(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, ['bucket' => ['nullable', 'in:0-7,8-30,31-60,61-90,90+']]);
        $period = ReportPeriod::fromFilters($filters);

        $rows = $this->unallocated->exportRows($filters, $period, $this->canSeeContacts($request, 'customer'))->map(fn (array $row) => [
            $row['transaction_reference'], $row['payer']['name'], $row['payer']['type'], $row['amount'],
            $row['allocated_amount'], $row['designated_amount'], $row['available_amount'], $row['currency'],
            $row['received_at'], $row['payment_method'], $row['age_days'], $row['age_bucket'],
        ]);

        return CsvExporter::download($this->filename('unallocated-receipts', $period), [
            'Receipt', 'Payer', 'Payer type', 'Original amount', 'Allocated', 'Designated credit', 'Available',
            'Currency', 'Received at', 'Method', 'Age (days)', 'Age bucket',
        ], $rows);
    }

    public function receivables(Request $request): JsonResponse
    {
        $filters = $this->filters($request, [
            'payment_state' => ['nullable', 'in:unpaid,partially_paid,fully_paid'],
            'overdue_only' => ['nullable', 'boolean'],
        ]);
        $period = ReportPeriod::fromFilters($filters);
        $page = $this->receivables->register($filters, $period);

        return $this->paged($page, $this->receivables->summary($filters, $period), 'invoice issue date');
    }

    public function receivablesExport(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, [
            'payment_state' => ['nullable', 'in:unpaid,partially_paid,fully_paid'],
            'overdue_only' => ['nullable', 'boolean'],
        ]);
        $period = ReportPeriod::fromFilters($filters);

        $rows = $this->receivables->exportRows($filters, $period)->map(fn (array $row) => [
            $row['invoice_reference'], $row['payer']['name'], $row['payer']['type'], $row['invoice_date'], $row['due_date'],
            $row['currency'], $row['invoice_total'], $row['allocated_amount'], $row['credit_note_applied_amount'],
            $row['credit_applied_amount'], $row['outstanding_amount'], $row['payment_state'], $row['days_overdue'], $row['ageing_bucket'],
        ]);

        return CsvExporter::download($this->filename('accounts-receivable', $period), [
            'Invoice', 'Payer', 'Payer type', 'Invoice date', 'Due date', 'Currency', 'Invoice total', 'Allocated',
            'Credit note applied', 'Credit applied', 'Outstanding', 'Payment state', 'Days overdue', 'Ageing',
        ], $rows);
    }

    public function customerCredit(Request $request): JsonResponse
    {
        $filters = $this->filters($request, ['include_zero' => ['nullable', 'in:0,1']]);
        $period = ReportPeriod::fromFilters($filters);

        return $this->paged(
            $this->credit->portfolio('customer', $filters, $period, $this->canSeeContacts($request, 'customer')),
            $this->credit->totals('customer', $filters, $period),
            'ledger created_at',
        );
    }

    public function customerCreditStatement(Request $request, int $customer): JsonResponse
    {
        $filters = $this->filters($request);
        $period = ReportPeriod::fromFilters($filters);

        return $this->envelope($this->credit->statement('customer', $customer, $filters, $period), 'ledger created_at');
    }

    public function customerCreditExport(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, ['include_zero' => ['nullable', 'in:0,1']]);
        $period = ReportPeriod::fromFilters($filters);

        return $this->creditExport('customer', $filters, $period, $this->canSeeContacts($request, 'customer'));
    }

    public function organisationCredit(Request $request): JsonResponse
    {
        $filters = $this->filters($request, ['include_zero' => ['nullable', 'in:0,1']]);
        $period = ReportPeriod::fromFilters($filters);

        return $this->paged(
            $this->credit->portfolio('organisation', $filters, $period, $this->canSeeContacts($request, 'organisation')),
            $this->credit->totals('organisation', $filters, $period),
            'ledger created_at',
        );
    }

    public function organisationCreditStatement(Request $request, int $organisation): JsonResponse
    {
        $filters = $this->filters($request);
        $period = ReportPeriod::fromFilters($filters);

        return $this->envelope($this->credit->statement('organisation', $organisation, $filters, $period), 'ledger created_at');
    }

    public function organisationCreditExport(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, ['include_zero' => ['nullable', 'in:0,1']]);
        $period = ReportPeriod::fromFilters($filters);

        return $this->creditExport('organisation', $filters, $period, $this->canSeeContacts($request, 'organisation'));
    }

    public function refunds(Request $request): JsonResponse
    {
        $filters = $this->filters($request, [
            'status' => ['nullable', 'in:requested,approved,submitting,submitted,confirmed,rejected,failed'],
            'provider' => ['nullable', 'string', 'max:40'],
            'basis' => ['nullable', 'in:requested,confirmed'],
        ]);
        $period = ReportPeriod::fromFilters($filters);

        return $this->paged($this->refunds->register($filters, $period), $this->refunds->summary($filters, $period), 'requested_at');
    }

    public function refundsExport(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, [
            'status' => ['nullable', 'in:requested,approved,submitting,submitted,confirmed,rejected,failed'],
            'provider' => ['nullable', 'string', 'max:40'],
            'basis' => ['nullable', 'in:requested,confirmed'],
        ]);
        $period = ReportPeriod::fromFilters($filters);

        $rows = $this->refunds->exportRows($filters, $period)->map(fn (array $row) => [
            $row['refund_reference'], $row['receipt_reference'], $row['payer']['name'], $row['payer']['type'], $row['amount'],
            $row['currency'], $row['status'], $row['stage'], $row['reason'], $row['requesting_staff'], $row['approving_staff'],
            $row['provider'], $row['provider_submission_status'], $row['provider_confirmation_reference'],
            $row['requested_at'], $row['completed_at'],
        ]);

        return CsvExporter::download($this->filename('refunds', $period), [
            'Refund reference', 'Receipt', 'Payer', 'Payer type', 'Amount', 'Currency', 'Status', 'Stage', 'Reason',
            'Requested by', 'Approved by', 'Provider', 'Provider submission', 'Confirmation reference', 'Requested at', 'Completed at',
        ], $rows);
    }

    public function creditNotes(Request $request): JsonResponse
    {
        $filters = $this->filters($request, [
            'status' => ['nullable', 'in:draft,issued,voided'],
            'basis' => ['nullable', 'in:issued,created'],
        ]);
        $period = ReportPeriod::fromFilters($filters);

        return $this->paged($this->creditNotes->register($filters, $period), $this->creditNotes->summary($filters, $period), 'issued_at');
    }

    public function creditNotesExport(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, [
            'status' => ['nullable', 'in:draft,issued,voided'],
            'basis' => ['nullable', 'in:issued,created'],
        ]);
        $period = ReportPeriod::fromFilters($filters);

        $rows = $this->creditNotes->exportRows($filters, $period)->map(fn (array $row) => [
            $row['credit_note_number'], $row['original_invoice'], $row['payer']['name'], $row['payer']['type'], $row['amount'],
            $row['applied_to_origin_amount'], $row['remaining_amount'], $row['currency'], $row['reason'], $row['issued_at'],
            $row['issuing_staff'], $row['status'], $row['application_status'], $row['voided_at'], $row['void_reason'],
        ]);

        return CsvExporter::download($this->filename('credit-notes', $period), [
            'Credit note', 'Original invoice', 'Payer', 'Payer type', 'Amount', 'Applied to originating invoice',
            'Remaining (FIFO)', 'Currency', 'Reason', 'Issued at', 'Issuing staff', 'Status', 'Application', 'Voided at', 'Void reason',
        ], $rows);
    }

    public function activity(Request $request): JsonResponse
    {
        $filters = $this->filters($request, [
            'event_type' => ['nullable', 'string', 'max:40'],
        ]);
        $period = ReportPeriod::fromFilters($filters);

        return $this->paged($this->activity->register($filters, $period), [], 'event time');
    }

    public function activityExport(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, ['event_type' => ['nullable', 'string', 'max:40']]);
        $period = ReportPeriod::fromFilters($filters);

        $rows = $this->activity->exportRows($filters, $period)->map(fn (array $row) => [
            $row['event_at'], $row['event_type'], $row['reference'], $row['subject'], $row['actor'], $row['amount'],
            $row['currency'], $row['reason'],
        ]);

        return CsvExporter::download($this->filename('financial-activity', $period), [
            'Event at', 'Type', 'Reference', 'Subject', 'Actor', 'Amount', 'Currency', 'Reason',
        ], $rows);
    }

    private function creditExport(string $payerType, array $filters, ReportPeriod $period, bool $withContacts): StreamedResponse
    {
        $rows = $this->credit->exportRows($payerType, $filters, $period, $withContacts)->map(fn (array $row) => [
            $row['payer']['name'], $row['payer']['reference'] ?? '', $row['currency'], $row['opening_balance'],
            $row['credited'], $row['designated'], $row['credit_note_credit'], $row['applied'], $row['reversed'],
            $row['closing_balance'], $row['last_activity_at'],
        ]);

        return CsvExporter::download($this->filename($payerType.'-credit', $period), [
            'Payer', 'Reference', 'Currency', 'Opening balance', 'Credited', 'Cash designated', 'Credit-note credit',
            'Applied', 'Reversed', 'Closing balance', 'Last activity',
        ], $rows);
    }

    private function filters(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'size:3'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'max:30'],
            'direction' => ['nullable', 'in:asc,desc'],
        ], $extra));
    }

    private function paged(LengthAwarePaginator $page, array $summary, string $basis): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
                'date_basis' => $basis,
                'currencies' => $this->currencies(),
                'summary' => $summary,
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function envelope(mixed $data, string $basis): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => ['date_basis' => $basis, 'currencies' => $this->currencies()],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** Currencies present in the ledger, so the UI never offers a cross-currency total. */
    private function currencies(): array
    {
        if ($this->currencyCache !== null) {
            return $this->currencyCache;
        }

        $currencies = DB::table('payment_transactions')->distinct()->pluck('currency')
            ->merge(DB::table('commercial_documents')->distinct()->pluck('currency'))
            ->filter()->unique()->sort()->values()->all();

        return $this->currencyCache = $currencies === [] ? ['GHS'] : $currencies;
    }

    private function filename(string $report, ReportPeriod $period): string
    {
        return "stage4d-{$report}-{$period->from}-to-{$period->to}.csv";
    }

    private function canSeeContacts(Request $request, string $payerType): bool
    {
        $user = $request->user();
        if ($user->hasRole('super-admin')) {
            return true;
        }

        return $user->hasAnyPermission($payerType === 'organisation' ? ['organisations.view_financials'] : ['customers.view_financials']);
    }
}
