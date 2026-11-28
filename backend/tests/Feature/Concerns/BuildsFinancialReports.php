<?php

namespace Tests\Feature\Concerns;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CreditNoteService;
use App\Services\CustomerCreditService;
use App\Services\PaymentAllocationService;
use App\Services\RefundService;

/**
 * Fixture builders for Stage 4D reporting tests. Records are created through the
 * Stage 4A–4C services wherever those services own the financial rules, so the
 * reports are exercised against the same data shape production produces.
 */
trait BuildsFinancialReports
{
    protected function stage4dPermissions(): array
    {
        return [
            'financials.reports.dashboard.view', 'financials.reports.receipts.view', 'financials.reports.allocations.view',
            'financials.reports.unallocated.view', 'financials.reports.receivables.view', 'financials.reports.customer_credit.view',
            'financials.reports.organisation_credit.view', 'financials.reports.refunds.view', 'financials.reports.credit_notes.view',
            'financials.reports.activity.view', 'financials.reports.settlements.view', 'financials.reports.export',
            'financials.reconciliation.view', 'financials.reconciliation.manage', 'financials.reconciliation.review',
            'customers.view_financials', 'organisations.view_financials',
        ];
    }

    protected function staffWithPermissions(array $slugs): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'Role '.uniqid(), 'slug' => 'role-'.uniqid()]);
        foreach ($slugs as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'description' => $slug]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    protected function customerFixture(string $name = 'Report Customer', string $phone = '0200000701'): Customer
    {
        return Customer::create([
            'name' => $name, 'phone' => $phone, 'email' => uniqid('report-').'@example.test',
            'password' => 'test-password', 'is_active' => true,
        ]);
    }

    protected function organisationFixture(string $name = 'Report Organisation', ?Customer $owner = null): Organisation
    {
        return Organisation::create([
            'reference' => 'ORG-'.strtoupper(uniqid()),
            'owner_customer_id' => $owner?->id,
            'name' => $name,
            'status' => Organisation::STATUS_ACTIVE,
        ]);
    }

    protected function invoiceFixture(Customer|Organisation $payer, string $total, string $number, array $overrides = []): CommercialDocument
    {
        return CommercialDocument::create(array_merge([
            'document_number' => $number,
            'document_type' => CommercialDocument::TYPE_INVOICE,
            'status' => CommercialDocument::STATUS_ISSUED,
            // Organisation documents keep the member customer as their recipient,
            // matching CommercialDocumentService::assertRecipient().
            'customer_id' => $payer instanceof Customer ? $payer->id : $payer->owner_customer_id,
            'organisation_id' => $payer instanceof Organisation ? $payer->id : null,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(30)->toDateString(),
            'currency' => 'GHS',
            'subtotal' => $total,
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => $total,
            'issued_at' => now(),
        ], $overrides));
    }

    protected function receiptFixture(array $overrides = []): PaymentTransaction
    {
        $reference = $overrides['transaction_reference'] ?? 'RPT-'.strtoupper(uniqid());
        $receivedAt = $overrides['received_at'] ?? now();

        return PaymentTransaction::create(array_merge([
            'order_id' => null,
            'payment_purpose' => 'invoice',
            'transaction_reference' => $reference,
            'provider' => null,
            'payment_method' => 'cash',
            'amount' => '100.00',
            'currency' => 'GHS',
            'status' => 'succeeded',
            'verified_at' => $receivedAt,
            'received_at' => $receivedAt,
            'reporting_date' => $receivedAt->toDateString(),
        ], $overrides));
    }

    protected function orderReceiptFixture(string $amount, string $reference, array $overrides = []): PaymentTransaction
    {
        $order = Order::create([
            'order_number' => 'RPT-ORDER-'.strtoupper(uniqid()),
            'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'Walk-in Guest',
            'customer_phone' => '0200000799',
            'status' => Order::STATUS_COMPLETED,
            'subtotal' => $amount,
            'delivery_fee' => '0.00',
            'total' => $amount,
            'payment_method' => 'cash',
            'payment_status' => Order::PAYMENT_STATUS_PAID,
        ]);

        return $this->receiptFixture(array_merge([
            'order_id' => $order->id,
            'payment_purpose' => 'order',
            'transaction_reference' => $reference,
            'amount' => $amount,
        ], $overrides));
    }

    protected function allocateReceipt(PaymentTransaction $payment, CommercialDocument $invoice, string $amount, string $reference, User $actor): void
    {
        app(PaymentAllocationService::class)->allocate($payment->id, [
            ['commercial_document_id' => $invoice->id, 'amount' => $amount],
        ], $reference, $actor);
    }

    protected function designateCredit(PaymentTransaction $payment, string $amount, string $reference, User $actor): void
    {
        app(CustomerCreditService::class)->classifyReceipt($payment->id, 'deposit', $reference.'-intent', 'Deposit received', $actor);
        app(CustomerCreditService::class)->designateCredit($payment->id, $amount, $reference, 'Customer deposit held as credit', $actor);
    }

    protected function issueCreditNote(CommercialDocument $invoice, string $amount, string $notes, User $creator, User $issuer): CommercialDocument
    {
        $service = app(CreditNoteService::class);
        $note = $service->create($creator, [
            'originating_document_id' => $invoice->id,
            'amount' => $amount,
            'notes' => $notes,
        ]);

        return $service->issue($issuer, $note);
    }

    protected function refundFixture(PaymentTransaction $payment, string $amount, string $reference, User $requester, User $approver, bool $confirm = false): void
    {
        $service = app(RefundService::class);
        $refund = $service->create($payment->id, $amount, 'Customer refund for reporting fixture', $reference, $requester);
        $service->approve($refund->id, $approver);
        if ($confirm) {
            $service->submit($refund->id, $approver);
            $service->confirm($refund->id, $approver, 'CASH-'.$reference, 'Cash returned, signed receipt on file');
        }
    }
}
