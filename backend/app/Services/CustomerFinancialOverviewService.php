<?php

namespace App\Services;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\PaymentTransaction;
use App\Models\RefundRequest;

/**
 * Permission-gated customer financial summary.
 *
 * Every figure is a total of records the application already maintains and is
 * computed with the existing financial services — never recalculated from
 * scratch in this class or in the browser:
 *
 *  - payments: succeeded payment_transactions rows attributed to the customer;
 *  - deposits: the Stage 4B deposit/advance receipt query in DepositService;
 *  - outstanding invoices: PaymentAllocationService::invoiceOutstandingMinor()
 *    per issued, non-void invoice (allocations, reversals and applied credit
 *    included), exactly as the invoice balance endpoints report;
 *  - credit: CustomerCreditService::balanceMinor() from the append-only ledger;
 *  - refunds: confirmed refund_requests totals plus in-flight counts.
 */
class CustomerFinancialOverviewService
{
    public const CURRENCY = 'GHS';

    public function __construct(
        private readonly CustomerCreditService $credit,
        private readonly PaymentAllocationService $allocations,
        private readonly DepositService $deposits,
    ) {}

    /** @return array<string, mixed> */
    public function overview(Customer $customer): array
    {
        $payments = PaymentTransaction::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'succeeded');

        $paymentsTotal = (string) (clone $payments)->selectRaw('COALESCE(SUM(amount), 0) as total')->value('total');
        $paymentsCount = (clone $payments)->count();
        $lastPaymentAt = (clone $payments)->max('created_at');

        $deposits = $this->deposits->customerDepositTotals($customer);

        // Outstanding balances are delegated to the authoritative allocation
        // service so the customer profile can never disagree with the invoice
        // and allocation screens.
        $invoices = CommercialDocument::query()
            ->where('customer_id', $customer->id)
            ->where('document_type', CommercialDocument::TYPE_INVOICE)
            ->where('status', CommercialDocument::STATUS_ISSUED)
            ->whereNotNull('issued_at')
            ->whereNull('voided_at')
            ->get(['id', 'document_number', 'total', 'currency']);

        $outstandingMinor = 0;
        foreach ($invoices as $invoice) {
            $outstandingMinor += $this->allocations->invoiceOutstandingMinor($invoice);
        }

        $refunds = RefundRequest::query()->where('customer_id', $customer->id);
        $refundsTotal = (string) (clone $refunds)->where('status', RefundRequest::STATUS_CONFIRMED)
            ->selectRaw('COALESCE(SUM(amount), 0) as total')->value('total');
        $refundsConfirmedCount = (clone $refunds)->where('status', RefundRequest::STATUS_CONFIRMED)->count();
        $refundsPendingCount = (clone $refunds)->whereIn('status', [
            RefundRequest::STATUS_REQUESTED,
            RefundRequest::STATUS_APPROVED,
            RefundRequest::STATUS_SUBMITTING,
            RefundRequest::STATUS_SUBMITTED,
        ])->count();

        return [
            'currency' => self::CURRENCY,
            'payments_received' => $this->money($paymentsTotal),
            'payments_count' => $paymentsCount,
            'last_payment_at' => $lastPaymentAt,
            'deposits_total' => $deposits['total'],
            'deposits_count' => $deposits['count'],
            'outstanding_invoices' => PaymentAllocationService::formatMinorUnits($outstandingMinor),
            'outstanding_invoice_count' => $invoices->count(),
            'credit_balance' => PaymentAllocationService::formatMinorUnits($this->credit->balanceMinor($customer, self::CURRENCY)),
            'refunds_total' => $this->money($refundsTotal),
            'refunds_confirmed_count' => $refundsConfirmedCount,
            'refunds_pending_count' => $refundsPendingCount,
            'generated_at' => now()->toISOString(),
        ];
    }

    private function money(string $amount): string
    {
        return PaymentAllocationService::formatMinorUnits(PaymentAllocationService::toMinorUnits($amount));
    }
}
