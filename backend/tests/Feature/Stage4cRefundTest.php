<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\RefundRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerCreditService;
use App\Services\PaymentAllocationService;
use App\Services\RefundService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Stage4cRefundTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private User $requester;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create([
            'name' => 'Stage 4C Refund Customer', 'phone' => '0200000903', 'email' => 'stage4c-refund@example.test',
            'password' => 'old-customer-password', 'is_active' => true,
        ]);
        $this->requester = User::factory()->create(['is_active' => true, 'name' => 'Refund Requester']);
        $this->approver = User::factory()->create(['is_active' => true, 'name' => 'Refund Approver']);
    }

    public function test_full_manual_refund_lifecycle_and_payment_status_is_preserved(): void
    {
        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-1');
        $service = app(RefundService::class);

        $refund = $service->create($payment->id, '100.00', 'Customer cancelled the order', 'refund-ref-0001', $this->requester);
        $this->assertSame('requested', $refund->status);

        $service->approve($refund->id, $this->approver, 'Approved by finance');
        $service->submit($refund->id, $this->approver);
        $confirmed = $service->confirm($refund->id, $this->approver, 'CASH-REF-1', 'Cash returned to customer, signed receipt on file');

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('CASH-REF-1', $confirmed->verification_reference);
        $this->assertSame('succeeded', $payment->fresh()->status, 'D12a: the receipt stays succeeded by default.');
        $this->assertTrue(AdminAuditLog::where('action', 'stage4c.refund.confirmed')->exists());
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits($service->refundableMinor($payment->fresh())));
    }

    public function test_partial_refund_is_allowed_and_reduces_the_refundable_balance(): void
    {
        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-2');
        $service = app(RefundService::class);
        $refund = $service->create($payment->id, '40.00', 'Partial goodwill refund', 'refund-ref-0002', $this->requester);
        $service->approve($refund->id, $this->approver);
        $service->submit($refund->id, $this->approver);
        $service->confirm($refund->id, $this->approver, 'CASH-REF-2', 'Partial cash refund evidence');

        $this->assertSame('60.00', PaymentAllocationService::formatMinorUnits($service->refundableMinor($payment->fresh())));
    }

    public function test_duplicate_and_conflicting_refund_references_are_handled(): void
    {
        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-3');
        $service = app(RefundService::class);
        $first = $service->create($payment->id, '100.00', 'Duplicate test', 'refund-ref-0003', $this->requester);
        $replay = $service->create($payment->id, '100.00', 'Duplicate test', 'refund-ref-0003', $this->requester);
        $this->assertSame($first->id, $replay->id);

        $this->expectException(DomainException::class);
        $service->create($payment->id, '100.00', 'Different reason', 'refund-ref-0003', $this->requester);
    }

    public function test_over_refund_and_concurrent_full_refunds_are_rejected(): void
    {
        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-4');
        $service = app(RefundService::class);

        $service->create($payment->id, '100.00', 'First full refund', 'refund-ref-0004', $this->requester);

        try {
            $service->create($payment->id, '100.00', 'Second concurrent refund', 'refund-ref-0005', $this->requester);
            $this->fail('A live refund must reserve the payment funds.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('refundable', $exception->getMessage());
        }

        $other = $this->payment('100.00', 'STAGE4C-REF-PAY-5');
        $this->expectException(DomainException::class);
        $service->create($other->id, '100.01', 'Over refund', 'refund-ref-0006', $this->requester);
    }

    public function test_allocated_funds_and_designated_credit_cannot_be_refunded(): void
    {
        $invoice = CommercialDocument::create([
            'document_number' => 'STAGE4C-REF-INV-1', 'document_type' => 'invoice', 'status' => 'issued',
            'customer_id' => $this->customer->id, 'currency' => 'GHS', 'subtotal' => '100.00',
            'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => '100.00', 'issue_date' => today(), 'issued_at' => now(),
        ]);
        $allocated = $this->payment('100.00', 'STAGE4C-REF-PAY-6');
        app(PaymentAllocationService::class)->allocate($allocated->id, [['commercial_document_id' => $invoice->id, 'amount' => '100.00']], 'stage4c-ref-alloc-1', $this->requester);

        $service = app(RefundService::class);
        try {
            $service->create($allocated->id, '100.00', 'Refund allocated funds', 'refund-ref-0007', $this->requester);
            $this->fail('Allocated funds must not be refundable.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('refundable', $exception->getMessage());
        }

        $designated = $this->payment('100.00', 'STAGE4C-REF-PAY-7');
        $credit = app(CustomerCreditService::class);
        $credit->classifyReceipt($designated->id, 'deposit', 'stage4c-ref-intent-1', 'Deposit', $this->requester);
        $credit->designateCredit($designated->id, '100.00', 'stage4c-ref-credit-1', 'Designated credit', $this->requester);

        $this->expectException(DomainException::class);
        $service->create($designated->id, '100.00', 'Refund designated credit', 'refund-ref-0008', $this->requester);
    }

    public function test_initiator_cannot_approve_their_own_refund_and_forged_returns_cannot_confirm(): void
    {
        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-8');
        $service = app(RefundService::class);
        $refund = $service->create($payment->id, '100.00', 'Separation test', 'refund-ref-0009', $this->requester);

        try {
            $service->approve($refund->id, $this->requester);
            $this->fail('The initiator must not approve their own refund.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different staff member', $exception->getMessage());
        }

        $service->approve($refund->id, $this->approver);
        try {
            $service->confirm($refund->id, $this->approver, 'CASH-REF-9', 'evidence');
            $this->fail('A refund cannot be confirmed before submission.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('submitted', $exception->getMessage());
        }

        $service->submit($refund->id, $this->approver);
        $this->expectException(DomainException::class);
        $service->confirm($refund->id, $this->approver, null, null);
    }

    public function test_unknown_provider_outcome_is_held_unresolved_and_cannot_be_resubmitted(): void
    {
        config(['stage4c.refunds.provider_submission_enabled' => true]);
        $this->paystackGateway();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-9', ['provider' => 'paystack', 'provider_reference' => 'PSK-REF-9']);
        $service = app(RefundService::class);
        $refund = $service->create($payment->id, '100.00', 'Provider timeout test', 'refund-ref-0010', $this->requester);
        $service->approve($refund->id, $this->approver);

        try {
            $service->submit($refund->id, $this->approver);
            $this->fail('A provider timeout must not be treated as a completed or failed refund.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('unresolved', $exception->getMessage());
        }

        $held = $refund->fresh();
        $this->assertSame('submitting', $held->status, 'An unknown provider outcome must stay unresolved.');
        $this->assertSame('unknown', $held->metadata['provider_outcome']);
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits($service->refundableMinor($payment->fresh())), 'Unresolved funds stay reserved.');
        $this->assertDatabaseHas('refund_events', ['refund_request_id' => $refund->id, 'action' => 'provider_error']);

        // A second submission must be refused without calling the provider again.
        try {
            $service->submit($refund->id, $this->approver);
            $this->fail('An unresolved refund must not be resubmitted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Reconcile it before submitting again', $exception->getMessage());
        }
        $this->assertSame('submitting', $refund->fresh()->status);
    }

    public function test_submission_intent_is_persisted_before_the_provider_is_called_with_a_stable_reference(): void
    {
        config(['stage4c.refunds.provider_submission_enabled' => true]);
        $this->paystackGateway();
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen['status_during_call'] = RefundRequest::query()->sole()->status;
            $seen['body'] = $request->data();
            return Http::response(['status' => true, 'data' => ['id' => 901, 'status' => 'pending', 'amount' => 10000, 'currency' => 'GHS', 'reference' => $request->data()['reference']]], 200);
        });

        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-13', ['provider' => 'paystack', 'provider_reference' => 'PSK-REF-13']);
        $service = app(RefundService::class);
        $refund = $service->create($payment->id, '100.00', 'Intent ordering test', 'refund-ref-0013', $this->requester);
        $service->approve($refund->id, $this->approver);
        $submitted = $service->submit($refund->id, $this->approver);

        $expectedKey = $service->providerIdempotencyKey('refund-ref-0013');
        $this->assertSame('submitting', $seen['status_during_call'], 'The intent must be durable before the provider call.');
        $this->assertSame($expectedKey, $seen['body']['reference'], 'Paystack receives the stable refund reference.');
        $this->assertSame($expectedKey, $service->providerIdempotencyKey('refund-ref-0013'), 'The reference is deterministic across retries.');
        $this->assertSame('submitted', $submitted->status);
        $this->assertSame('901', $submitted->provider_refund_reference);
    }

    public function test_definitive_provider_rejection_returns_the_refund_to_approved(): void
    {
        config(['stage4c.refunds.provider_submission_enabled' => true]);
        $this->paystackGateway();
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => false, 'message' => 'Invalid transaction'], 422)]);

        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-14', ['provider' => 'paystack', 'provider_reference' => 'PSK-REF-14']);
        $service = app(RefundService::class);
        $refund = $service->create($payment->id, '100.00', 'Definitive rejection test', 'refund-ref-0014', $this->requester);
        $service->approve($refund->id, $this->approver);

        try {
            $service->submit($refund->id, $this->approver);
            $this->fail('A definitive provider rejection must not be treated as submitted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('rejected the refund', $exception->getMessage());
        }

        $this->assertSame('approved', $refund->fresh()->status, 'No money moved, so the refund returns to approved.');
        $this->assertDatabaseHas('refund_events', ['refund_request_id' => $refund->id, 'action' => 'provider_error', 'to_status' => 'approved']);
    }

    public function test_reconciliation_records_an_accepted_provider_refund(): void
    {
        config(['stage4c.refunds.provider_submission_enabled' => true]);
        $this->paystackGateway();
        $service = app(RefundService::class);
        $key = $service->providerIdempotencyKey('refund-ref-0015');
        $lookupAvailable = false;
        Http::fake(function ($request) use (&$lookupAvailable, $key) {
            if ($request->method() === 'POST' || ! $lookupAvailable) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }
            return Http::response(['status' => true, 'data' => [
                ['id' => 1234, 'status' => 'pending', 'amount' => 10000, 'currency' => 'GHS', 'reference' => $key],
            ]], 200);
        });

        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-15', ['provider' => 'paystack', 'provider_reference' => 'PSK-REF-15']);
        $refund = $service->create($payment->id, '100.00', 'Reconciliation test', 'refund-ref-0015', $this->requester);
        $service->approve($refund->id, $this->approver);
        try { $service->submit($refund->id, $this->approver); } catch (DomainException) { /* outcome unknown */ }

        $lookupAvailable = true;

        $reconciled = $service->reconcile($refund->id, $this->approver);
        $this->assertSame('submitted', $reconciled->status);
        $this->assertSame('1234', $reconciled->provider_refund_reference);
        $this->assertSame('accepted', $reconciled->metadata['provider_outcome']);
        $this->assertDatabaseHas('refund_events', ['refund_request_id' => $refund->id, 'action' => 'reconciled']);
    }

    public function test_reconciliation_requires_explicit_absent_confirmation_before_allowing_resubmission(): void
    {
        config(['stage4c.refunds.provider_submission_enabled' => true]);
        $this->paystackGateway();
        $lookupAvailable = false;
        Http::fake(function ($request) use (&$lookupAvailable) {
            if ($request->method() === 'POST' || ! $lookupAvailable) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }
            return Http::response(['status' => true, 'data' => []], 200);
        });

        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-16', ['provider' => 'paystack', 'provider_reference' => 'PSK-REF-16']);
        $service = app(RefundService::class);
        $refund = $service->create($payment->id, '100.00', 'Absent reconciliation test', 'refund-ref-0016', $this->requester);
        $service->approve($refund->id, $this->approver);
        try { $service->submit($refund->id, $this->approver); } catch (DomainException) { /* outcome unknown */ }

        $lookupAvailable = true;

        try {
            $service->reconcile($refund->id, $this->approver);
            $this->fail('An absent provider refund must need explicit operator confirmation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('confirm_absent', $exception->getMessage());
        }
        $this->assertSame('submitting', $refund->fresh()->status);

        $returned = $service->reconcile($refund->id, $this->approver, true);
        $this->assertSame('approved', $returned->status);
        $this->assertSame('absent_confirmed', $returned->metadata['provider_outcome']);
        $this->assertDatabaseHas('refund_events', ['refund_request_id' => $refund->id, 'action' => 'reconciled_absent']);
    }

    public function test_reconciliation_stays_unresolved_when_the_provider_cannot_be_checked_or_the_amount_differs(): void
    {
        config(['stage4c.refunds.provider_submission_enabled' => true]);
        $this->paystackGateway();
        $service = app(RefundService::class);
        $key = $service->providerIdempotencyKey('refund-ref-0017');
        $lookup = 'down';
        Http::fake(function ($request) use (&$lookup, $key) {
            if ($request->method() === 'POST') throw new ConnectionException('cURL error 28: Operation timed out');
            if ($lookup === 'down') return Http::response(['status' => false], 503);
            return Http::response(['status' => true, 'data' => [
                ['id' => 4321, 'status' => 'pending', 'amount' => 9999, 'currency' => 'GHS', 'reference' => $key],
            ]], 200);
        });

        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-17', ['provider' => 'paystack', 'provider_reference' => 'PSK-REF-17']);
        $refund = $service->create($payment->id, '100.00', 'Unavailable reconciliation test', 'refund-ref-0017', $this->requester);
        $service->approve($refund->id, $this->approver);
        try { $service->submit($refund->id, $this->approver); } catch (DomainException) { /* outcome unknown */ }

        try {
            $service->reconcile($refund->id, $this->approver);
            $this->fail('An unavailable provider lookup must not resolve the refund.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('could not be checked', $exception->getMessage());
        }
        $this->assertSame('submitting', $refund->fresh()->status);

        $lookup = 'mismatch';
        try {
            $service->reconcile($refund->id, $this->approver);
            $this->fail('A mismatched provider refund must not be accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }
        $this->assertSame('submitting', $refund->fresh()->status);
        $this->assertDatabaseHas('refund_events', ['refund_request_id' => $refund->id, 'action' => 'reconciliation_mismatch']);
    }

    public function test_reconcile_endpoint_requires_approval_permission(): void
    {
        $payment = $this->payment('10.00', 'STAGE4C-REF-PAY-18');
        $service = app(RefundService::class);
        $refund = $service->create($payment->id, '10.00', 'Reconcile permission test', 'refund-ref-0018', $this->requester);

        $viewer = $this->staffWith(['financials.refunds.view']);
        Sanctum::actingAs($viewer);
        $this->postJson('/api/v1/admin/financials/refunds/'.$refund->id.'/reconcile')->assertForbidden();
    }

    public function test_provider_pending_then_verified_confirmation(): void
    {
        config(['stage4c.refunds.provider_submission_enabled' => true]);
        $this->paystackGateway();
        Http::fake([
            'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 555, 'status' => 'pending', 'amount' => 10000, 'currency' => 'GHS']], 200),
            'api.paystack.co/refund/555' => Http::response(['status' => true, 'data' => ['status' => 'processed', 'amount' => 10000, 'currency' => 'GHS']], 200),
        ]);

        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-10', ['provider' => 'paystack', 'provider_reference' => 'PSK-REF-10']);
        $service = app(RefundService::class);
        $refund = $service->create($payment->id, '100.00', 'Provider refund', 'refund-ref-0011', $this->requester);
        $service->approve($refund->id, $this->approver);
        $submitted = $service->submit($refund->id, $this->approver);
        $this->assertSame('submitted', $submitted->status);
        $this->assertSame('555', $submitted->provider_refund_reference);

        $confirmed = $service->confirm($refund->id, $this->approver, null, null);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('555', $confirmed->verification_reference);
    }

    public function test_provider_amount_or_currency_mismatch_is_not_confirmed(): void
    {
        config(['stage4c.refunds.provider_submission_enabled' => true]);
        $this->paystackGateway();
        Http::fake([
            'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 777, 'status' => 'pending', 'amount' => 10000, 'currency' => 'GHS']], 200),
            'api.paystack.co/refund/777' => Http::response(['status' => true, 'data' => ['status' => 'processed', 'amount' => 9999, 'currency' => 'GHS']], 200),
        ]);

        $payment = $this->payment('100.00', 'STAGE4C-REF-PAY-11', ['provider' => 'paystack', 'provider_reference' => 'PSK-REF-11']);
        $service = app(RefundService::class);
        $refund = $service->create($payment->id, '100.00', 'Mismatch test', 'refund-ref-0012', $this->requester);
        $service->approve($refund->id, $this->approver);
        $service->submit($refund->id, $this->approver);

        $this->expectException(DomainException::class);
        $service->confirm($refund->id, $this->approver, null, null);
    }

    public function test_refund_endpoints_enforce_permissions_and_customer_isolation(): void
    {
        $payment = $this->payment('50.00', 'STAGE4C-REF-PAY-12');

        $viewer = $this->staffWith(['financials.refunds.view']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/admin/financials/refunds')->assertOk();
        $this->getJson('/api/v1/admin/financials/refunds/eligible-payments')->assertOk();
        $this->postJson('/api/v1/admin/financials/refunds', [
            'payment_transaction_id' => $payment->id, 'amount' => '50.00', 'reason' => 'Viewer cannot initiate',
            'refund_reference' => 'refund-http-0001',
        ])->assertForbidden();

        $initiator = $this->staffWith(['financials.refunds.initiate']);
        Sanctum::actingAs($initiator);
        $created = $this->postJson('/api/v1/admin/financials/refunds', [
            'payment_transaction_id' => $payment->id, 'amount' => '50.00', 'reason' => 'HTTP initiated refund',
            'refund_reference' => 'refund-http-0002',
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/admin/financials/refunds/'.$created['id'].'/approve')->assertForbidden();

        $this->customer->createToken('customer-session');
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/admin/financials/refunds')->assertForbidden();
    }

    private function payment(string $amount, string $reference, array $overrides = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'order_id' => null, 'customer_id' => $this->customer->id, 'organisation_id' => null, 'payment_purpose' => 'invoice',
            'transaction_reference' => $reference, 'payment_method' => 'cash', 'provider' => 'cash',
            'amount' => $amount, 'currency' => 'GHS', 'status' => 'succeeded', 'verified_at' => now(),
            'received_at' => now(), 'reporting_date' => now()->toDateString(),
        ], $overrides));
    }

    private function paystackGateway(): PaymentGateway
    {
        return PaymentGateway::create([
            'provider' => 'paystack', 'display_name' => 'Paystack Test', 'public_key' => 'pk_test_stage4c',
            'secret_key' => 'sk_test_stage4c', 'is_enabled' => true, 'is_default' => true,
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
