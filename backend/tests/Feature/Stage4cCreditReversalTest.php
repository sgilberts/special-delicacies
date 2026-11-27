<?php

namespace Tests\Feature;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\CustomerCreditLedgerEntry;
use App\Models\Organisation;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerCreditReversalService;
use App\Services\CustomerCreditService;
use App\Services\PaymentAllocationService;
use App\Services\RefundService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Stage4cCreditReversalTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private User $initiator;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create([
            'name' => 'Stage 4C Reversal Customer', 'phone' => '0200000901', 'email' => 'stage4c-reversal@example.test',
            'password' => 'old-customer-password', 'is_active' => true,
        ]);
        $this->initiator = User::factory()->create(['is_active' => true, 'name' => 'Reversal Initiator']);
        $this->approver = User::factory()->create(['is_active' => true, 'name' => 'Reversal Approver']);
    }

    public function test_valid_reversal_preserves_original_entry_and_reduces_derived_balance(): void
    {
        [$payment, $entry] = $this->creditedReceipt('100.00');
        $service = app(CustomerCreditReversalService::class);
        $this->assertSame('100.00', PaymentAllocationService::formatMinorUnits(app(CustomerCreditService::class)->balanceMinor($this->customer, 'GHS')));

        $request = $service->initiate($entry->id, '100.00', 'rev-ref-0001', 'Customer requested return of unspent credit', $this->initiator);
        $this->assertSame('requested', $request->status);
        $this->assertSame(0, CustomerCreditLedgerEntry::where('entry_type', 'reversal')->count());

        $approved = $service->approve($request->id, $this->approver);
        $this->assertSame('approved', $approved->status);

        $reversal = CustomerCreditLedgerEntry::where('entry_type', 'reversal')->sole();
        $this->assertSame((int) $entry->id, (int) $reversal->reverses_entry_id);
        $this->assertSame('100.00', $reversal->amount);
        $this->assertSame('GHS', $reversal->currency);
        $this->assertSame((int) $this->customer->id, (int) $reversal->customer_id);
        $this->assertNull($reversal->organisation_id);
        $this->assertSame((int) $this->approver->id, (int) $reversal->authorized_by_user_id);
        $this->assertSame('Reversal Approver', $reversal->authorized_by_name_snapshot);
        $this->assertSame('100.00', $reversal->credit_balance_before);
        $this->assertSame('0.00', $reversal->credit_balance_after);

        // The original designation row is untouched and still append-only.
        $original = $entry->fresh();
        $this->assertSame('designation', $original->entry_type);
        $this->assertSame('100.00', $original->amount);
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits(app(CustomerCreditService::class)->balanceMinor($this->customer, 'GHS')));
    }

    public function test_duplicate_request_and_approval_are_idempotent(): void
    {
        [, $entry] = $this->creditedReceipt('50.00');
        $service = app(CustomerCreditReversalService::class);

        $first = $service->initiate($entry->id, '50.00', 'rev-ref-0002', 'Duplicate test', $this->initiator);
        $replay = $service->initiate($entry->id, '50.00', 'rev-ref-0002', 'Duplicate test', $this->initiator);
        $this->assertSame($first->id, $replay->id);

        $service->approve($first->id, $this->approver);
        $service->approve($first->id, $this->approver);
        $this->assertSame(1, CustomerCreditLedgerEntry::where('entry_type', 'reversal')->count());
    }

    public function test_over_reversal_and_consumed_credit_are_rejected(): void
    {
        [$payment, $entry] = $this->creditedReceipt('100.00');
        $invoice = $this->invoice('200.00', 'STAGE4C-REV-INV-1');
        app(CustomerCreditService::class)->applyCredit($invoice->id, '60.00', 'stage4c-rev-apply-1', 'Apply credit to invoice', $this->initiator);

        $service = app(CustomerCreditReversalService::class);
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits($service->remainingReversibleMinor($entry->fresh())));

        $this->expectException(DomainException::class);
        $service->initiate($entry->id, '60.00', 'rev-ref-0003', 'Attempt to reverse consumed credit', $this->initiator);
    }

    public function test_reversal_of_fully_consumed_credit_is_rejected(): void
    {
        [, $entry] = $this->creditedReceipt('100.00');
        $invoice = $this->invoice('200.00', 'STAGE4C-REV-INV-2');
        app(CustomerCreditService::class)->applyCredit($invoice->id, '100.00', 'stage4c-rev-apply-2', 'Fully consume credit', $this->initiator);

        $this->assertSame(0, app(CustomerCreditReversalService::class)->remainingReversibleMinor($entry->fresh()));
        $this->expectException(DomainException::class);
        app(CustomerCreditReversalService::class)->initiate($entry->id, '1.00', 'rev-ref-0004', 'Attempt spent credit', $this->initiator);
    }

    public function test_initiator_cannot_approve_own_reversal(): void
    {
        [, $entry] = $this->creditedReceipt('30.00');
        $service = app(CustomerCreditReversalService::class);
        $request = $service->initiate($entry->id, '30.00', 'rev-ref-0005', 'Separation test', $this->initiator);

        $this->expectException(DomainException::class);
        $service->approve($request->id, $this->initiator);
    }

    public function test_concurrent_reversal_requests_cannot_exceed_remaining_credit(): void
    {
        [, $entry] = $this->creditedReceipt('100.00');
        $service = app(CustomerCreditReversalService::class);

        $first = $service->initiate($entry->id, '60.00', 'rev-ref-0006', 'First concurrent request', $this->initiator);
        $second = $service->initiate($entry->id, '60.00', 'rev-ref-0007', 'Second concurrent request', $this->initiator);

        $service->approve($first->id, $this->approver);
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits($service->remainingReversibleMinor($entry->fresh())));

        try {
            $service->approve($second->id, $this->approver);
            $this->fail('The second reversal must not exceed the remaining reversible credit.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('no longer reversible', $exception->getMessage());
        }
        $this->assertSame(1, CustomerCreditLedgerEntry::where('entry_type', 'reversal')->count());
    }

    public function test_organisation_credit_reversal_preserves_payer_and_currency(): void
    {
        $organisation = Organisation::create([
            'reference' => 'STAGE4C-ORG-1', 'owner_customer_id' => $this->customer->id,
            'name' => 'Stage 4C Organisation', 'status' => 'active',
        ]);
        $payment = PaymentTransaction::create([
            'order_id' => null, 'customer_id' => null, 'organisation_id' => $organisation->id, 'payment_purpose' => 'invoice',
            'transaction_reference' => 'STAGE4C-ORG-PAY-1', 'payment_method' => 'cash', 'amount' => '75.00', 'currency' => 'GHS',
            'status' => 'succeeded', 'verified_at' => now(), 'received_at' => now(), 'reporting_date' => now()->toDateString(),
        ]);
        $credit = app(CustomerCreditService::class);
        $credit->classifyReceipt($payment->id, 'deposit', 'stage4c-org-intent-1', 'Organisation deposit', $this->initiator);
        $entry = $credit->designateCredit($payment->id, '75.00', 'stage4c-org-credit-1', 'Organisation credit', $this->initiator);

        $service = app(CustomerCreditReversalService::class);
        $request = $service->initiate($entry->id, '25.00', 'rev-ref-0008', 'Partial organisation reversal', $this->initiator);
        $service->approve($request->id, $this->approver);

        $reversal = CustomerCreditLedgerEntry::where('entry_type', 'reversal')->sole();
        $this->assertNull($reversal->customer_id);
        $this->assertSame((int) $organisation->id, (int) $reversal->organisation_id);
        $this->assertSame('GHS', $reversal->currency);
        $this->assertSame('50.00', PaymentAllocationService::formatMinorUnits($credit->balanceMinor($organisation, 'GHS')));
    }

    public function test_reversal_endpoints_enforce_permissions_and_customer_isolation(): void
    {
        [, $entry] = $this->creditedReceipt('20.00');

        $viewer = $this->staffWith(['financials.credit_reversals.view']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/admin/financials/credit-reversals/eligible')->assertOk();
        $this->postJson('/api/v1/admin/financials/credit-reversals', [
            'ledger_entry_id' => $entry->id, 'amount' => '20.00', 'reason' => 'Viewer cannot initiate',
            'operation_reference' => 'rev-http-0001',
        ])->assertForbidden();

        $initiator = $this->staffWith(['financials.credit_reversals.initiate']);
        Sanctum::actingAs($initiator);
        $created = $this->postJson('/api/v1/admin/financials/credit-reversals', [
            'ledger_entry_id' => $entry->id, 'amount' => '20.00', 'reason' => 'HTTP initiated reversal',
            'operation_reference' => 'rev-http-0002',
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/admin/financials/credit-reversals/'.$created['id'].'/approve')->assertForbidden();

        $this->customer->createToken('customer-session');
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/admin/financials/credit-reversals')->assertForbidden();
        $this->postJson('/api/v1/admin/financials/credit-reversals/'.$created['id'].'/approve')->assertForbidden();
    }

    public function test_reversing_designated_credit_releases_the_receipt_funds(): void
    {
        [$payment, $entry] = $this->creditedReceipt('100.00');
        $allocations = app(PaymentAllocationService::class);
        $refunds = app(RefundService::class);

        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits($allocations->availableMinorForPayment($payment->id)));
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits($refunds->refundableMinor($payment->fresh())));

        $service = app(CustomerCreditReversalService::class);
        $request = $service->initiate($entry->id, '100.00', 'rev-ref-0101', 'Customer returned the unspent advance', $this->initiator);
        $service->approve($request->id, $this->approver);

        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits(app(CustomerCreditService::class)->balanceMinor($this->customer, 'GHS')));
        $this->assertSame('100.00', PaymentAllocationService::formatMinorUnits($allocations->availableMinorForPayment($payment->id)));
        $this->assertSame('100.00', PaymentAllocationService::formatMinorUnits($refunds->refundableMinor($payment->fresh())));
    }

    public function test_partial_reversal_releases_only_the_reversed_portion_of_receipt_funds(): void
    {
        [$payment, $entry] = $this->creditedReceipt('100.00');
        $allocations = app(PaymentAllocationService::class);
        $refunds = app(RefundService::class);
        $credit = app(CustomerCreditService::class);

        $service = app(CustomerCreditReversalService::class);
        $request = $service->initiate($entry->id, '40.00', 'rev-ref-0201', 'Partial return of unspent credit', $this->initiator);
        $service->approve($request->id, $this->approver);

        $this->assertSame('60.00', PaymentAllocationService::formatMinorUnits($credit->balanceMinor($this->customer, 'GHS')));
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits($allocations->availableMinorForPayment($payment->id)));
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits($refunds->refundableMinor($payment->fresh())));

        // The original entry is untouched; only an appended reversal exists.
        $this->assertSame('designation', $entry->fresh()->entry_type);
        $this->assertSame('100.00', $entry->fresh()->amount);
        $this->assertSame('40.00', CustomerCreditLedgerEntry::where('entry_type', 'reversal')->sole()->amount);
    }

    public function test_multiple_reversals_accumulate_and_cannot_over_reverse(): void
    {
        [$payment, $entry] = $this->creditedReceipt('100.00');
        $allocations = app(PaymentAllocationService::class);
        $service = app(CustomerCreditReversalService::class);

        foreach ([['30.00', 'rev-ref-0202'], ['30.00', 'rev-ref-0203']] as [$amount, $reference]) {
            $request = $service->initiate($entry->id, $amount, $reference, 'Sequential partial reversal', $this->initiator);
            $service->approve($request->id, $this->approver);
        }

        $this->assertSame(2, CustomerCreditLedgerEntry::where('entry_type', 'reversal')->count());
        $this->assertSame('60.00', PaymentAllocationService::formatMinorUnits($allocations->availableMinorForPayment($payment->id)));
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits($service->remainingReversibleMinor($entry->fresh())));

        $this->expectException(DomainException::class);
        $service->initiate($entry->id, '50.00', 'rev-ref-0204', 'Over reversal attempt', $this->initiator);
    }

    public function test_reversed_funds_cannot_remain_available_as_both_credit_and_receipt_funds(): void
    {
        [$payment, $entry] = $this->creditedReceipt('100.00');
        $allocations = app(PaymentAllocationService::class);
        $refunds = app(RefundService::class);
        $credit = app(CustomerCreditService::class);

        $service = app(CustomerCreditReversalService::class);
        $request = $service->initiate($entry->id, '100.00', 'rev-ref-0205', 'Full return of unspent credit', $this->initiator);
        $service->approve($request->id, $this->approver);

        $creditBalance = PaymentAllocationService::formatMinorUnits($credit->balanceMinor($this->customer, 'GHS'));
        $receiptFunds = PaymentAllocationService::formatMinorUnits($allocations->availableMinorForPayment($payment->id));
        $refundable = PaymentAllocationService::formatMinorUnits($refunds->refundableMinor($payment->fresh()));

        $this->assertSame('0.00', $creditBalance);
        $this->assertSame('100.00', $receiptFunds);
        $this->assertSame('100.00', $refundable);

        // Once the released funds are allocated they are no longer refundable.
        $invoice = $this->invoice('100.00', 'STAGE4C-REV-INV-3');
        $allocations->allocate($payment->id, [['commercial_document_id' => $invoice->id, 'amount' => '100.00']], 'stage4c-rev-alloc-1', $this->initiator);
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits($allocations->availableMinorForPayment($payment->id)));
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits($refunds->refundableMinor($payment->fresh())));
    }

    public function test_reversal_respects_existing_allocations(): void
    {
        $payment = $this->receipt('100.00');
        $allocations = app(PaymentAllocationService::class);
        $credit = app(CustomerCreditService::class);
        $invoice = $this->invoice('40.00', 'STAGE4C-REV-INV-4');
        $allocations->allocate($payment->id, [['commercial_document_id' => $invoice->id, 'amount' => '40.00']], 'stage4c-rev-alloc-2', $this->initiator);
        $credit->classifyReceipt($payment->id, 'advance', 'stage4c-rev-intent-4', 'Advance for allocation test', $this->initiator);
        $entry = $credit->designateCredit($payment->id, '60.00', 'stage4c-rev-credit-4', 'Designate the remainder', $this->initiator);

        // 60 designated + 40 allocated = the whole receipt.
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits($allocations->availableMinorForPayment($payment->id)));

        $service = app(CustomerCreditReversalService::class);
        $request = $service->initiate($entry->id, '60.00', 'rev-ref-0206', 'Return the designated part only', $this->initiator);
        $service->approve($request->id, $this->approver);

        $this->assertSame('60.00', PaymentAllocationService::formatMinorUnits($allocations->availableMinorForPayment($payment->id)));
        $this->assertSame('60.00', PaymentAllocationService::formatMinorUnits(app(RefundService::class)->refundableMinor($payment->fresh())));
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits($allocations->paymentAllocatedMinorForPayment($payment->id)), 'The existing allocation is still respected.');
    }

    public function test_receipt_funds_stay_correct_after_application_and_a_later_reversal(): void
    {
        [$payment, $entry] = $this->creditedReceipt('100.00');
        $allocations = app(PaymentAllocationService::class);
        $credit = app(CustomerCreditService::class);
        $invoice = $this->invoice('60.00', 'STAGE4C-REV-INV-5');

        $credit->applyCredit($invoice->id, '60.00', 'stage4c-rev-apply-5', 'Consume part of the credit', $this->initiator);
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits($credit->balanceMinor($this->customer, 'GHS')));

        $service = app(CustomerCreditReversalService::class);
        $request = $service->initiate($entry->id, '40.00', 'rev-ref-0207', 'Return the unconsumed remainder', $this->initiator);
        $service->approve($request->id, $this->approver);

        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits($credit->balanceMinor($this->customer, 'GHS')));
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits($allocations->availableMinorForPayment($payment->id)));
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits(app(RefundService::class)->refundableMinor($payment->fresh())));
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits($allocations->invoiceOutstandingMinor($invoice->fresh())), 'The consumed 60 stays applied to the invoice.');
    }

    public function test_duplicate_reversal_reference_cannot_restore_funds_twice(): void
    {
        [$payment, $entry] = $this->creditedReceipt('100.00');
        $allocations = app(PaymentAllocationService::class);
        $service = app(CustomerCreditReversalService::class);

        $first = $service->initiate($entry->id, '40.00', 'rev-ref-0208', 'Idempotent partial reversal', $this->initiator);
        $replay = $service->initiate($entry->id, '40.00', 'rev-ref-0208', 'Idempotent partial reversal', $this->initiator);
        $this->assertSame($first->id, $replay->id);

        $service->approve($first->id, $this->approver);
        $service->approve($replay->id, $this->approver);

        $this->assertSame(1, CustomerCreditLedgerEntry::where('entry_type', 'reversal')->count());
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits($allocations->availableMinorForPayment($payment->id)));
        $this->assertSame('60.00', PaymentAllocationService::formatMinorUnits($service->remainingReversibleMinor($entry->fresh())));
    }

    private function receipt(string $amount): PaymentTransaction
    {
        return PaymentTransaction::create([
            'order_id' => null, 'customer_id' => $this->customer->id, 'organisation_id' => null, 'payment_purpose' => 'invoice',
            'transaction_reference' => 'STAGE4C-PAY-'.substr(md5($amount.microtime()), 0, 8), 'payment_method' => 'cash',
            'amount' => $amount, 'currency' => 'GHS', 'status' => 'succeeded', 'verified_at' => now(),
            'received_at' => now(), 'reporting_date' => now()->toDateString(),
        ]);
    }

    private function creditedReceipt(string $amount): array
    {
        $payment = $this->receipt($amount);
        $credit = app(CustomerCreditService::class);
        $credit->classifyReceipt($payment->id, 'advance', 'stage4c-intent-'.substr(md5((string) $payment->id), 0, 8), 'Advance for reversal test', $this->initiator);
        $entry = $credit->designateCredit($payment->id, $amount, 'stage4c-credit-'.substr(md5((string) $payment->id), 0, 8), 'Credit for reversal test', $this->initiator);
        return [$payment, $entry];
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

    private function staffWith(array $slugs): User
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
}
