<?php

namespace App\Services;

use App\Models\CommercialDocument;
use App\Models\Organisation;
use App\Models\PaymentTransaction;
use App\Models\RefundRequest;

/**
 * Permission-gated organisation financial summary.
 *
 * Every figure comes from the approved Stage 4B/4C services and the records
 * they own — this class never invents a balance and the browser never
 * recalculates one:
 *
 *  - payments: succeeded payment_transactions rows attributed to the organisation;
 *  - deposits: the same deposit receipt query DepositService uses, scoped to the
 *    organisation payer;
 *  - outstanding invoices: PaymentAllocationService::invoiceOutstandingMinor()
 *    per issued, non-void organisation invoice;
 *  - credit: CustomerCreditService::balanceMinor() from the append-only ledger;
 *  - refunds: confirmed refund_requests totals plus in-flight counts.
 */
class OrganisationFinancialOverviewService
{
    public const CURRENCY = 'GHS';

    public function __construct(
        private readonly CustomerCreditService $credit,
        private readonly PaymentAllocationService $allocations,
    ) {}

    /** @return array<string, mixed> */
    public function overview(Organisation $organisation): array
    {
        $payments = PaymentTransaction::query()
            ->where('organisation_id', $organisation->id)
            ->where('status', 'succeeded');

        $paymentsTotal = (string) (clone $payments)->selectRaw('COALESCE(SUM(amount), 0) as total')->value('total');
        $paymentsCount = (clone $payments)->count();
        $lastPaymentAt = (clone $payments)->max('created_at');

        $deposits = PaymentTransaction::query()
            ->where('organisation_id', $organisation->id)
            ->whereNull('order_id')
            ->where('payment_purpose', 'invoice')
            ->where('status', 'succeeded')
            ->where(fn ($origin) => $origin
                ->where('metadata->source', 'customer_online_deposit')
                ->orWhere('metadata->source', 'staff_deposit')
                ->orWhereHas('receiptIntent'));

        $depositsTotal = (string) (clone $deposits)->selectRaw('COALESCE(SUM(amount), 0) as total')->value('total');

        $invoices = CommercialDocument::query()
            ->where('organisation_id', $organisation->id)
            ->where('document_type', CommercialDocument::TYPE_INVOICE)
            ->where('status', CommercialDocument::STATUS_ISSUED)
            ->whereNotNull('issued_at')
            ->whereNull('voided_at')
            ->get(['id', 'document_number', 'total', 'currency']);

        $outstandingMinor = 0;
        foreach ($invoices as $invoice) {
            $outstandingMinor += $this->allocations->invoiceOutstandingMinor($invoice);
        }

        $refunds = RefundRequest::query()->where('organisation_id', $organisation->id);
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
            'deposits_total' => $this->money($depositsTotal),
            'deposits_count' => (clone $deposits)->count(),
            'outstanding_invoices' => PaymentAllocationService::formatMinorUnits($outstandingMinor),
            'outstanding_invoice_count' => $invoices->count(),
            'credit_balance' => PaymentAllocationService::formatMinorUnits($this->credit->balanceMinor($organisation, self::CURRENCY)),
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
