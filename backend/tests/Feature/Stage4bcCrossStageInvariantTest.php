<?php

namespace Tests\Feature;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\CustomerCreditLedgerEntry;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffDepositRecord;
use App\Models\User;
use App\Services\CreditNoteService;
use App\Services\CustomerCreditReversalService;
use App\Services\CustomerCreditService;
use App\Services\DepositService;
use App\Services\PaymentAllocationService;
use App\Services\RefundService;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cross-stage (4B + 4C) financial invariants.
 *
 * These tests exist only on the integration branch: each stage's own suite
 * exercises its stage in isolation, so this file drives the seams between
 * deposits, credit designation, credit application, credit notes, reversals and
 * refunds against one ledger.
 */
class Stage4bcCrossStageInvariantTest extends TestCase
{
    use RefreshDatabase;

    private const STAGE4C_PERMISSIONS = [
        'financials.refunds.view', 'financials.refunds.initiate', 'financials.refunds.approve',
        'financials.credit_notes.view', 'financials.credit_notes.create', 'financials.credit_notes.approve',
        'financials.credit_reversals.view', 'financials.credit_reversals.initiate', 'financials.credit_reversals.approve',
    ];

    private Customer $customer;
    private User $recorder;
    private User $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create([
            'name' => 'Cross Stage Customer', 'phone' => '0200000801', 'email' => 'cross-stage@example.test',
            'password' => 'old-customer-password', 'is_active' => true,
        ]);
        $this->recorder = User::factory()->create(['is_active' => true, 'name' => 'Deposit Recorder']);
        $this->verifier = User::factory()->create(['is_active' => true, 'name' => 'Independent Verifier']);
    }

    // ---------------------------------------------------------------- 4B → 4C

    public function test_deposit_verification_designation_and_invoice_application_chain(): void
    {
        $deposits = app(DepositService::class);
        $credit = app(CustomerCreditService::class);
        $allocations = app(PaymentAllocationService::class);

        $record = $deposits->recordStaffDeposit($this->recorder, [
            'intent' => 'deposit', 'amount' => '100.00', 'payment_method' => 'bank_transfer',
            'external_reference' => 'CROSS-BANK-0001', 'evidence' => 'Bank statement line 42, counterfoil filed.',
            'payer_type' => 'customer', 'customer_id' => $this->customer->id,
        ]);
        $this->assertSame(StaffDepositRecord::STATUS_PENDING, $record->status);
        $payment = PaymentTransaction::findOrFail($record->payment_transaction_id);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(0, CustomerCreditLedgerEntry::query()->count());

        try {
            $deposits->decideStaffDeposit($this->recorder, $record->id, true, 'Self approval attempt');
            $this->fail('The recording staff member must not verify their own deposit.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different staff member', $exception->getMessage());
        }

        $verified = $deposits->decideStaffDeposit($this->verifier, $record->id, true, 'Bank statement matched.');
        $this->assertSame(StaffDepositRecord::STATUS_VERIFIED, $verified->status);
        $payment->refresh();
        $this->assertSame('succeeded', $payment->status);
        $this->assertNotNull($payment->received_at);
        $this->assertSame('deposit', $payment->receiptIntent()->firstOrFail()->intent);

        $entry = $credit->designateCredit($payment->id, '100.00', 'cross-designation-0001', 'Verified deposit credited', $this->verifier);
        $this->assertSame('100.00', $entry->amount);
        $this->assertSame(10000, $credit->balanceMinor($this->customer, 'GHS'));
        $this->assertSame(0, $allocations->availableMinorForPayment($payment->id));

        $invoice = $this->invoice('200.00', 'CROSS-INV-0001');
        $credit->applyCredit($invoice->id, '60.00', 'cross-application-0001', 'Part-pay invoice from deposit credit', $this->verifier);

        $this->assertSame(4000, $credit->balanceMinor($this->customer, 'GHS'));
        $this->assertSame(14000, $allocations->invoiceOutstandingMinor($invoice->fresh()));

        try {
            $credit->designateCredit($payment->id, '10.00', 'cross-designation-0002', 'Over-designation', $this->verifier);
            $this->fail('A fully designated receipt must not accept another designation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('exceeds', strtolower($exception->getMessage()));
        }
    }

    public function test_rejected_deposit_never_becomes_credit(): void
    {
        $deposits = app(DepositService::class);
        $record = $deposits->recordStaffDeposit($this->recorder, [
            'intent' => 'deposit', 'amount' => '75.00', 'payment_method' => 'cash',
            'external_reference' => 'CROSS-CASH-0002', 'evidence' => 'Cash counted at the counter, receipt pad 12.',
            'payer_type' => 'customer', 'customer_id' => $this->customer->id,
        ]);

        $rejected = $deposits->decideStaffDeposit($this->verifier, $record->id, false, 'Statement does not show the credit.');
        $this->assertSame(StaffDepositRecord::STATUS_REJECTED, $rejected->status);

        $payment = PaymentTransaction::findOrFail($record->payment_transaction_id);
        $this->assertSame('cancelled', $payment->status);
        $this->assertNull($payment->receiptIntent()->first());
        $this->assertSame(0, CustomerCreditLedgerEntry::query()->count());

        $this->expectException(DomainException::class);
        app(CustomerCreditService::class)->designateCredit($payment->id, '10.00', 'cross-designation-0003', 'Rejected deposit', $this->verifier);
    }

    public function test_credit_reversal_recalculates_allocatable_and_refundable_balances(): void
    {
        $payment = $this->receipt('100.00', 'CROSS-PAY-REV-1');
        $credit = app(CustomerCreditService::class);
        $reversals = app(CustomerCreditReversalService::class);

        $credit->classifyReceipt($payment->id, 'deposit', 'cross-intent-rev-1', 'Deposit classification', $this->verifier);
        $entry = $credit->designateCredit($payment->id, '100.00', 'cross-designation-rev-1', 'Deposit credited', $this->verifier);
        $this->assertSame(10000, $credit->balanceMinor($this->customer, 'GHS'));

        $request = $reversals->initiate($entry->id, '40.00', 'cross-reversal-rev-1', 'Return unspent deposit', $this->recorder);

        try {
            $reversals->approve($request->id, $this->recorder);
            $this->fail('A reversal must be approved by a different staff member.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different staff member', $exception->getMessage());
        }

        $approved = $reversals->approve($request->id, $this->verifier);
        $this->assertSame('approved', $approved->status);

        $this->assertSame(6000, $credit->balanceMinor($this->customer, 'GHS'));
        $this->assertSame(4000, app(PaymentAllocationService::class)->availableMinorForPayment($payment->id));
        $this->assertSame(4000, app(RefundService::class)->refundableMinor($payment->fresh()));

        try {
            $reversals->initiate($entry->id, '70.00', 'cross-reversal-rev-2', 'Over reversal', $this->recorder);
            $this->fail('Reversing more than the unconsumed credit must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('exceeds', strtolower($exception->getMessage()));
        }
    }

    // ---------------------------------------------------------------- 4C seams

    public function test_credit_note_issuance_application_and_void_eligibility(): void
    {
        $invoice = $this->invoice('200.00', 'CROSS-CN-INV-1');
        $notes = app(CreditNoteService::class);
        $credit = app(CustomerCreditService::class);
        $reversals = app(CustomerCreditReversalService::class);

        $note = $notes->create($this->recorder, ['originating_document_id' => $invoice->id, 'amount' => '50.00']);
        $issued = $notes->issue($this->verifier, $note->fresh());
        $this->assertSame('issued', $issued->status);

        $entry = $notes->creditEntryFor($issued);
        $this->assertNotNull($entry);
        $this->assertSame(15000, app(PaymentAllocationService::class)->invoiceOutstandingMinor($invoice->fresh()));
        $this->assertSame(0, $credit->balanceMinor($this->customer, 'GHS'));

        // Auto-applied credit is consumed, so the note can no longer be voided.
        $this->assertSame(0, $reversals->remainingReversibleMinor($entry));
        try {
            $notes->void($this->verifier, $issued, 'Cannot void used credit');
            $this->fail('A credit note whose credit is consumed must not be voidable.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('used', $exception->getMessage());
        }

        config(['stage4c.credit_notes.auto_apply' => false]);
        $second = $notes->create($this->recorder, ['originating_document_id' => $invoice->id, 'amount' => '30.00']);
        $secondIssued = $notes->issue($this->verifier, $second->fresh());
        $secondEntry = $notes->creditEntryFor($secondIssued);

        $this->assertSame(3000, $reversals->remainingReversibleMinor($secondEntry));
        $this->assertSame(3000, $credit->balanceMinor($this->customer, 'GHS'));

        try {
            $notes->void($this->recorder, $secondIssued, 'Creator cannot void');
            $this->fail('The creator must not void their own credit note.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different staff member', $exception->getMessage());
        }

        $voided = $notes->void($this->verifier, $secondIssued, 'Raised in error');
        $this->assertSame('voided', $voided->status);
        $this->assertSame(0, $credit->balanceMinor($this->customer, 'GHS'));
        $this->assertSame(0, $reversals->remainingReversibleMinor($secondEntry));
        $this->assertSame(15000, $notes->remainingCreditableMinor($invoice->fresh()));
    }

    public function test_refund_reservation_interacts_with_allocations_and_credit(): void
    {
        $invoice = $this->invoice('200.00', 'CROSS-REF-INV-1');
        $payment = $this->receipt('100.00', 'CROSS-REF-PAY-1');
        $credit = app(CustomerCreditService::class);
        $refunds = app(RefundService::class);

        app(PaymentAllocationService::class)->allocate(
            $payment->id,
            [['commercial_document_id' => $invoice->id, 'amount' => '30.00']],
            'cross-alloc-0001',
            $this->verifier,
        );
        $credit->classifyReceipt($payment->id, 'deposit', 'cross-intent-ref-1', 'Deposit classification', $this->verifier);
        $credit->designateCredit($payment->id, '20.00', 'cross-designation-ref-1', 'Deposit credit', $this->verifier);

        // 100.00 − 30.00 allocated − 20.00 designated = 50.00 refundable.
        $this->assertSame(5000, $refunds->refundableMinor($payment->fresh()));

        $refund = $refunds->create($payment->id, '50.00', 'Customer cancellation', 'cross-refund-0001', $this->recorder);
        $refunds->approve($refund->id, $this->verifier);
        $submitted = $refunds->submit($refund->id, $this->verifier);
        $this->assertSame('submitted', $submitted->status);
        $this->assertSame(0, $refunds->refundableMinor($payment->fresh()));

        try {
            $refunds->create($payment->id, '10.00', 'Second refund', 'cross-refund-0002', $this->recorder);
            $this->fail('Reserved refund funds must not be refundable twice.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('refundable', strtolower($exception->getMessage()));
        }

        $confirmed = $refunds->confirm($refund->id, $this->verifier, 'BANK-OUT-1', 'Bank transfer confirmation slip on file.');
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('succeeded', $payment->fresh()->status, 'D12a: the receipt is not rewritten by a refund.');
    }

    public function test_duplicate_operations_and_retries_are_rejected_or_idempotent(): void
    {
        $credit = app(CustomerCreditService::class);
        $first = $this->receipt('50.00', 'CROSS-DUP-PAY-1');
        $second = $this->receipt('50.00', 'CROSS-DUP-PAY-2');

        $intent = $credit->classifyReceipt($first->id, 'deposit', 'cross-intent-dup-1', 'Deposit', $this->verifier);
        $replay = $credit->classifyReceipt($first->id, 'deposit', 'cross-intent-dup-1', 'Deposit', $this->verifier);
        $this->assertSame($intent->id, $replay->id);

        try {
            $credit->classifyReceipt($second->id, 'deposit', 'cross-intent-dup-1', 'Deposit', $this->verifier);
            $this->fail('A receipt operation reference must not be reusable on another receipt.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already used', $exception->getMessage());
        }

        $entry = $credit->designateCredit($first->id, '50.00', 'cross-designation-dup-1', 'Credit', $this->verifier);
        $replayed = $credit->designateCredit($first->id, '50.00', 'cross-designation-dup-1', 'Credit', $this->verifier);
        $this->assertSame($entry->id, $replayed->id);

        try {
            $credit->designateCredit($first->id, '10.00', 'cross-designation-dup-1', 'Credit', $this->verifier);
            $this->fail('A reused designation reference with a different amount must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already used', $exception->getMessage());
        }

        $deposits = app(DepositService::class);
        $payload = [
            'intent' => 'deposit', 'amount' => '25.00', 'payment_method' => 'mobile_money',
            'external_reference' => 'CROSS-MOMO-0001', 'evidence' => 'Mobile money statement screenshot filed.',
            'payer_type' => 'customer', 'customer_id' => $this->customer->id,
        ];
        $deposits->recordStaffDeposit($this->recorder, $payload);
        try {
            $deposits->recordStaffDeposit($this->recorder, $payload);
            $this->fail('A duplicate external payment reference must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already been recorded', $exception->getMessage());
        }

        $refunds = app(RefundService::class);
        $refund = $refunds->create($second->id, '50.00', 'Duplicate retry', 'cross-refund-dup-1', $this->recorder);
        $again = $refunds->create($second->id, '50.00', 'Duplicate retry', 'cross-refund-dup-1', $this->recorder);
        $this->assertSame($refund->id, $again->id);
    }

    // ------------------------------------------------------- access and policy

    public function test_customer_verification_gate_covers_deposits_and_credit(): void
    {
        $customer = Customer::create([
            'name' => 'Unverified Cross Stage Customer', 'phone' => '0200000802', 'email' => 'cross-unverified@example.test',
            'password' => 'old-customer-password', 'is_active' => true,
        ]);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/customer/deposits')->assertForbidden()->assertJsonPath('code', 'customer_verification_required');
        $this->getJson('/api/v1/customer/credit')->assertForbidden();
        $this->postJson('/api/v1/customer/deposits', [])->assertForbidden();

        $customer->forceFill(['email_verified_at' => now()])->save();
        $this->getJson('/api/v1/customer/deposits')->assertForbidden()->assertJsonPath('missing', ['phone']);

        $customer->forceFill(['phone_verified_at' => now()])->save();
        $this->getJson('/api/v1/customer/deposits')->assertOk();
        $this->getJson('/api/v1/customer/credit')->assertOk();

        Sanctum::actingAs($this->verifier);
        $this->getJson('/api/v1/customer/deposits')->assertForbidden();
    }

    public function test_role_and_permission_behaviour_is_identical_after_migration_and_seeding(): void
    {
        // The Stage 4C migration has already run (RefreshDatabase) and created
        // the permissions without any role to grant them to.
        foreach (self::STAGE4C_PERMISSIONS as $slug) {
            $this->assertDatabaseHas('permissions', ['slug' => $slug]);
        }
        $this->assertNull(Role::where('slug', 'super-admin')->first());

        app(RoleAndPermissionSeeder::class)->run();

        $superAdmin = Role::where('slug', 'super-admin')->firstOrFail();
        foreach (self::STAGE4C_PERMISSIONS as $slug) {
            $this->assertTrue($superAdmin->permissions()->where('slug', $slug)->exists(), "super-admin missing {$slug}");
        }

        $admin = Role::where('slug', 'admin')->firstOrFail();
        $this->assertSame(0, $admin->permissions()->whereIn('slug', self::STAGE4C_PERMISSIONS)->count());
        $this->assertSame(0, $admin->permissions()->where('slug', 'like', 'financials.%')->count());

        // Re-seeding is idempotent: it must not drop or duplicate the Stage 4C
        // permissions the migration created.
        app(RoleAndPermissionSeeder::class)->run();
        $this->assertSame(count(self::STAGE4C_PERMISSIONS), Permission::whereIn('slug', self::STAGE4C_PERMISSIONS)->count());
        $this->assertSame(
            count(self::STAGE4C_PERMISSIONS),
            Role::where('slug', 'super-admin')->firstOrFail()->permissions()->whereIn('slug', self::STAGE4C_PERMISSIONS)->count(),
        );
    }

    public function test_derived_balance_equals_ledger_and_remaining_reversible_credit(): void
    {
        $invoice = $this->invoice('200.00', 'CROSS-INVARIANT-INV-1');
        $payment = $this->receipt('200.00', 'CROSS-INVARIANT-PAY-1');
        $credit = app(CustomerCreditService::class);
        $notes = app(CreditNoteService::class);
        $reversals = app(CustomerCreditReversalService::class);

        $credit->classifyReceipt($payment->id, 'deposit', 'cross-intent-invariant-1', 'Deposit', $this->verifier);
        $designation = $credit->designateCredit($payment->id, '120.00', 'cross-designation-invariant-1', 'Deposit credit', $this->verifier);
        $credit->applyCredit($invoice->id, '70.00', 'cross-application-invariant-1', 'Pay invoice', $this->verifier);

        $reversal = $reversals->initiate($designation->id, '20.00', 'cross-reversal-invariant-1', 'Return part', $this->recorder);
        $reversals->approve($reversal->id, $this->verifier);

        $note = $notes->create($this->recorder, ['originating_document_id' => $invoice->id, 'amount' => '40.00']);
        $notes->issue($this->verifier, $note->fresh());

        $credits = CustomerCreditLedgerEntry::query()
            ->whereIn('entry_type', CustomerCreditLedgerEntry::CREDIT_TYPES)->get()
            ->sum(fn (CustomerCreditLedgerEntry $entry) => PaymentAllocationService::toMinorUnits((string) $entry->amount));
        $debits = CustomerCreditLedgerEntry::query()
            ->whereIn('entry_type', CustomerCreditLedgerEntry::DEBIT_TYPES)->get()
            ->sum(fn (CustomerCreditLedgerEntry $entry) => PaymentAllocationService::toMinorUnits((string) $entry->amount));

        $balance = $credit->balanceMinor($this->customer, 'GHS');
        $this->assertSame(3000, $balance, '120 designated + 40 credit note − 70 applied − 40 credit note applied − 20 reversed.');
        $this->assertSame($credits - $debits, $balance);

        $remaining = CustomerCreditLedgerEntry::query()
            ->whereIn('entry_type', CustomerCreditLedgerEntry::CREDIT_TYPES)
            ->get()
            ->sum(fn (CustomerCreditLedgerEntry $entry) => $reversals->remainingReversibleMinor($entry));
        $this->assertSame($balance, $remaining, 'Sum of remaining reversible credit must equal the derived balance.');

        // 200 receipt − 0 allocations − (120 designated − 20 reversed) = 100 refundable.
        $this->assertSame(10000, app(RefundService::class)->refundableMinor($payment->fresh()));
    }

    // ---------------------------------------------------------------- helpers

    private function receipt(string $amount, string $reference): PaymentTransaction
    {
        return PaymentTransaction::create([
            'order_id' => null, 'customer_id' => $this->customer->id, 'organisation_id' => null,
            'payment_purpose' => 'invoice', 'transaction_reference' => $reference,
            'payment_method' => 'cash', 'provider' => 'cash', 'amount' => $amount, 'currency' => 'GHS',
            'status' => 'succeeded', 'verified_at' => now(), 'received_at' => now(),
            'reporting_date' => now()->toDateString(),
        ]);
    }

    private function invoice(string $total, string $number): CommercialDocument
    {
        return CommercialDocument::create([
            'document_number' => $number, 'document_type' => 'invoice', 'status' => 'issued',
            'customer_id' => $this->customer->id, 'currency' => 'GHS', 'subtotal' => $total,
            'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => $total,
            'issue_date' => today(), 'issued_at' => now(),
        ]);
    }
}
