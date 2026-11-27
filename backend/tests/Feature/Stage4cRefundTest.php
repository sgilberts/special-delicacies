<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerCreditService;
use App\Services\PaymentAllocationService;
use App\Services\RefundService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Stage4cRefundTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private User $requester;
    private User $approver;
    private User $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create([
            'name' => 'Stage Four C Customer', 'phone' => '0200000901', 'email' => 'stage4c@example.test',
            'password' => 'test-password', 'is_active' => true,
        ]);
        $this->requester = $this->userWith(['financials.refunds.view', 'financials.refunds.create', 'financials.refunds.submit', 'financials.refunds.cancel']);
        $this->approver = $this->userWith(['financials.refunds.view', 'financials.refunds.review', 'financials.refunds.approve']);
        $this->processor = $this->userWith(['financials.refunds.view', 'financials.refunds.process', 'financials.refunds.complete', 'financials.refunds.reverse']);
    }

    public function test_authorised_creation_uses_server_refundable_balance_and_rejects_over_refund(): void
    {
        $payment = $this->payment('100.00', 'S4C-PAY-1');
        $service = app(RefundService::class);

        $refund = $service->create($this->requester, $this->draftInput($payment, '40.00', 's4c-refund-0001'));
        $this->assertSame(Refund::STATUS_DRAFT, $refund->status);
        $this->assertSame('REF-'.now()->format('Y').'-'.str_pad((string) $refund->id, 8, '0', STR_PAD_LEFT), $refund->refund_reference);
        $this->assertSame('100.00', PaymentAllocationService::formatMinorUnits($service->refundableMinor($payment)));

        $service->submit($this->requester, $refund);
        $this->assertSame('60.00', PaymentAllocationService::formatMinorUnits($service->refundableMinor($payment)));

        try {
            $service->create($this->requester, $this->draftInput($payment, '70.00', 's4c-refund-0002'));
            $this->fail('A refund must not exceed the refundable balance.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('refundable balance', $exception->getMessage());
        }
        $this->assertSame(1, Refund::count());
    }

    public function test_multiple_partial_refunds_and_idempotent_operation_reference(): void
    {
        $payment = $this->payment('100.00', 'S4C-PAY-2');
        $service = app(RefundService::class);
        $first = $service->create($this->requester, $this->draftInput($payment, '25.00', 's4c-refund-0011'));
        $replay = $service->create($this->requester, $this->draftInput($payment, '25.00', 's4c-refund-0011'));
        $this->assertSame($first->id, $replay->id);

        $service->submit($this->requester, $first);
        $second = $service->create($this->requester, $this->draftInput($payment, '75.00', 's4c-refund-0012'));
        $service->submit($this->requester, $second);
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits($service->refundableMinor($payment)));

        try {
            $service->create($this->requester, $this->draftInput($payment, '0.01', 's4c-refund-0013'));
            $this->fail('No refundable balance remains.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('refundable balance', $exception->getMessage());
        }
    }

    public function test_concurrent_refund_requests_cannot_both_reserve_the_same_funds(): void
    {
        $payment = $this->payment('100.00', 'S4C-PAY-3');
        $service = app(RefundService::class);
        $first = $service->create($this->requester, $this->draftInput($payment, '100.00', 's4c-refund-0021'));
        $second = $service->create($this->requester, $this->draftInput($payment, '100.00', 's4c-refund-0022'));

        $service->submit($this->requester, $first);
        try {
            $service->submit($this->requester, $second);
            $this->fail('A second refund must not reserve already reserved funds.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('refundable balance', $exception->getMessage());
        }
        $this->assertSame(Refund::STATUS_DRAFT, $second->fresh()->status);
    }

    public function test_lifecycle_approval_requires_separation_of_duties_and_rejects_invalid_transitions(): void
    {
        $payment = $this->payment('50.00', 'S4C-PAY-4');
        $service = app(RefundService::class);
        $refund = $service->create($this->requester, $this->draftInput($payment, '50.00', 's4c-refund-0031'));

        try {
            $service->approve($this->requester, $refund);
            $this->fail('A draft cannot be approved directly.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cannot move', $exception->getMessage());
        }

        $service->submit($this->requester, $refund);
        $service->review($this->approver, $refund);
        try {
            $service->approve($this->requester, $refund);
            $this->fail('The requester must not approve their own refund.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different user', $exception->getMessage());
        }
        $approved = $service->approve($this->approver, $refund);
        $this->assertSame(Refund::STATUS_APPROVED, $approved->status);
        $this->assertSame($this->approver->id, $approved->approved_by);

        try {
            $service->submit($this->requester, $approved);
            $this->fail('An approved refund cannot be resubmitted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cannot move', $exception->getMessage());
        }

        $cancelled = $service->cancel($this->requester, $approved, 'Customer withdrew the request');
        $this->assertSame(Refund::STATUS_CANCELLED, $cancelled->status);
    }

    public function test_rejection_records_reason_and_blocks_processing(): void
    {
        $payment = $this->payment('50.00', 'S4C-PAY-5');
        $service = app(RefundService::class);
        $refund = $service->create($this->requester, $this->draftInput($payment, '50.00', 's4c-refund-0041'));
        $service->submit($this->requester, $refund);
        $rejected = $service->reject($this->approver, $refund, 'Payment was for a non-refundable event');
        $this->assertSame(Refund::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Payment was for a non-refundable event', $rejected->rejection_reason);

        try {
            $service->process($this->processor, $rejected);
            $this->fail('A rejected refund cannot be processed.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('approved', $exception->getMessage());
        }
    }

    public function test_gateway_refund_stays_processing_until_verified_and_duplicate_callbacks_are_safe(): void
    {
        $this->gatewayAccount();
        Http::fake([
            'api.paystack.co/refund/*' => Http::response(['status' => true, 'message' => 'Refund retrieved', 'data' => ['id' => 98765, 'status' => 'processed', 'amount' => 5000, 'currency' => 'GHS']], 200),
            'api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'Refund queued', 'data' => ['id' => 98765, 'status' => 'pending', 'amount' => 5000, 'currency' => 'GHS']], 200),
        ]);

        $payment = $this->payment('50.00', 'S4C-PAY-6', ['provider' => 'paystack', 'payment_method' => 'gateway', 'provider_reference' => 'PSK-TX-6']);
        $service = app(RefundService::class);
        $refund = $this->approvedRefund($service, $payment, '50.00', 's4c-refund-0051');

        $processing = $service->process($this->processor, $refund);
        $this->assertSame(Refund::STATUS_PROCESSING, $processing->status);
        $this->assertSame('98765', $processing->gateway_reference);
        $this->assertSame('pending', $processing->gateway_status);
        $this->assertNull($processing->completed_at);

        try {
            $service->process($this->processor, $processing);
            $this->fail('A refund already submitted to the gateway cannot be submitted twice.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('approved', $exception->getMessage());
        }

        $completed = $service->verifyGateway($this->processor, $processing);
        $this->assertSame(Refund::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->completed_at);

        $service->applyGatewayOutcome($completed, 'processed', '98765', 'processed', 5000, 'GHS');
        $this->assertSame(1, AdminAuditLog::where('action', 'refund.completed')->count());
        $this->assertSame(Refund::STATUS_COMPLETED, $completed->fresh()->status);
    }

    public function test_gateway_failure_is_recorded_and_never_completes(): void
    {
        $this->gatewayAccount();
        Http::fake([
            'api.paystack.co/refund' => Http::response(['status' => false, 'message' => 'Refund failed at provider'], 400),
        ]);

        $payment = $this->payment('30.00', 'S4C-PAY-7', ['provider' => 'paystack', 'payment_method' => 'gateway', 'provider_reference' => 'PSK-TX-7']);
        $service = app(RefundService::class);
        $refund = $this->approvedRefund($service, $payment, '30.00', 's4c-refund-0061');

        $failed = $service->process($this->processor, $refund);
        $this->assertSame(Refund::STATUS_FAILED, $failed->status);
        $this->assertSame('Refund failed at provider', $failed->failure_reason);
        $this->assertNull($failed->completed_at);
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits(app(CustomerCreditService::class)->balanceMinor($this->customer, 'GHS')));
    }

    public function test_retry_verifies_the_existing_provider_reference_instead_of_resubmitting(): void
    {
        $this->gatewayAccount();
        Http::fake([
            'api.paystack.co/refund/*' => Http::response(['status' => true, 'data' => ['id' => 55555, 'status' => 'processed', 'amount' => 3000, 'currency' => 'GHS']], 200),
            'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 55555, 'status' => 'pending']], 200),
        ]);

        $payment = $this->payment('30.00', 'S4C-PAY-7B', ['provider' => 'paystack', 'payment_method' => 'gateway', 'provider_reference' => 'PSK-TX-7B']);
        $service = app(RefundService::class);
        $refund = $this->approvedRefund($service, $payment, '30.00', 's4c-refund-0062');
        $processing = $service->process($this->processor, $refund);
        $this->assertSame('55555', $processing->gateway_reference);

        $failed = $service->applyGatewayOutcome($processing, 'failed', '55555', 'Provider reported a failure', 3000, 'GHS', $this->processor);
        $this->assertSame(Refund::STATUS_FAILED, $failed->status);

        $retried = $service->retry($this->processor, $failed);
        $this->assertSame(Refund::STATUS_COMPLETED, $retried->status);
        $postCount = collect(Http::recorded())->filter(fn (array $pair) => $pair[0]->method() === 'POST')->count();
        $this->assertSame(1, $postCount, 'A retry must verify the provider reference instead of submitting a second refund.');
    }

    public function test_customer_credit_refund_posts_once_to_the_stage4b_ledger(): void
    {
        $payment = $this->payment('80.00', 'S4C-PAY-8');
        $service = app(RefundService::class);
        $refund = $this->approvedRefund($service, $payment, '80.00', 's4c-refund-0071', Refund::METHOD_CUSTOMER_CREDIT);

        $completed = $service->process($this->processor, $refund);
        $this->assertSame(Refund::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->credit_ledger_entry_id);
        $this->assertSame('80.00', PaymentAllocationService::formatMinorUnits(app(CustomerCreditService::class)->balanceMinor($this->customer, 'GHS')));
        $this->assertSame(1, \App\Models\CustomerCreditLedgerEntry::where('entry_type', 'refund_credit')->count());

        $entry = app(CustomerCreditService::class)->postRefundCredit($completed, $this->processor);
        $this->assertSame($completed->credit_ledger_entry_id, $entry->id);
        $this->assertSame(1, \App\Models\CustomerCreditLedgerEntry::where('entry_type', 'refund_credit')->count());

        try {
            $service->process($this->processor, $completed);
            $this->fail('A completed refund cannot post credit twice.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('approved', $exception->getMessage());
        }
    }

    public function test_manual_refund_requires_verified_evidence_to_complete(): void
    {
        $payment = $this->payment('20.00', 'S4C-PAY-9', ['provider' => 'cash', 'payment_method' => 'cash']);
        $service = app(RefundService::class);
        $refund = $this->approvedRefund($service, $payment, '20.00', 's4c-refund-0081', Refund::METHOD_MANUAL);

        $processing = $service->process($this->processor, $refund);
        $this->assertSame(Refund::STATUS_PROCESSING, $processing->status);
        try {
            $service->complete($this->processor, $processing, null);
            $this->fail('A manual refund needs an external transaction reference.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('external transaction reference', $exception->getMessage());
        }
        $completed = $service->complete($this->processor, $processing, 'BANK-TRANSFER-7781');
        $this->assertSame(Refund::STATUS_COMPLETED, $completed->status);
        $this->assertSame('BANK-TRANSFER-7781', $completed->external_transaction_reference);
    }

    public function test_completed_refund_is_immutable_and_can_only_be_reversed_with_an_audit_reason(): void
    {
        $payment = $this->payment('15.00', 'S4C-PAY-10');
        $service = app(RefundService::class);
        $refund = $this->approvedRefund($service, $payment, '15.00', 's4c-refund-0091', Refund::METHOD_CUSTOMER_CREDIT);
        $completed = $service->process($this->processor, $refund);

        try {
            $service->cancel($this->requester, $completed, 'Attempt to delete a completed refund');
            $this->fail('A completed refund cannot be cancelled.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cannot move', $exception->getMessage());
        }

        $reversed = $service->reverse($this->processor, $completed, 'Provider confirmed the credit was duplicated');
        $this->assertSame(Refund::STATUS_REVERSED, $reversed->status);
        $this->assertSame('Provider confirmed the credit was duplicated', $reversed->reversal_reason);
        // Reversal is a record correction; the immutable ledger entry survives.
        $this->assertSame(1, \App\Models\CustomerCreditLedgerEntry::where('entry_type', 'refund_credit')->count());
    }

    public function test_currency_and_payment_eligibility_are_enforced(): void
    {
        $service = app(RefundService::class);
        $pending = $this->payment('10.00', 'S4C-PAY-11', ['status' => 'pending', 'verified_at' => null, 'received_at' => null]);
        try {
            $service->create($this->requester, $this->draftInput($pending, '10.00', 's4c-refund-0101'));
            $this->fail('A pending payment cannot be refunded.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('verified', $exception->getMessage());
        }

        $payment = $this->payment('10.00', 'S4C-PAY-12');
        $input = $this->draftInput($payment, '10.00', 's4c-refund-0102');
        $input['currency'] = 'USD';
        try {
            $service->create($this->requester, $input);
            $this->fail('A refund currency must match the original payment.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('currency', $exception->getMessage());
        }

        $invoice = $this->invoice($this->customer, '25.00', 'S4C-INV-1');
        $other = Customer::create(['name' => 'Other Payer', 'phone' => '0200000902', 'email' => 'other4c@example.test', 'password' => 'test-password', 'is_active' => true]);
        $otherInvoice = $this->invoice($other, '25.00', 'S4C-INV-2');
        $input = $this->draftInput($payment, '10.00', 's4c-refund-0103');
        $input['commercial_document_id'] = $otherInvoice->id;
        try {
            $service->create($this->requester, $input);
            $this->fail('A refund must not reference another payer’s document.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('payer', $exception->getMessage());
        }
        $input['commercial_document_id'] = $invoice->id;
        $linked = $service->create($this->requester, $input);
        $this->assertSame($invoice->id, $linked->commercial_document_id);
    }

    public function test_order_payment_refunds_derive_the_order_and_audit_every_step(): void
    {
        $order = Order::create([
            'order_number' => 'S4C-ORDER-1', 'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'Stage Four C Customer', 'customer_phone' => '0200000901', 'status' => Order::STATUS_COMPLETED,
            'subtotal' => '40.00', 'delivery_fee' => '0.00', 'total' => '40.00',
            'payment_method' => 'cash', 'payment_status' => Order::PAYMENT_STATUS_PAID,
        ]);
        $payment = $this->payment('40.00', 'S4C-PAY-13', ['order_id' => $order->id, 'payment_purpose' => 'order']);
        $service = app(RefundService::class);
        $refund = $service->create($this->requester, $this->draftInput($payment, '40.00', 's4c-refund-0111'));
        $this->assertSame($order->id, $refund->order_id);

        $service->submit($this->requester, $refund);
        $service->approve($this->approver, $refund->fresh());

        $actions = collect($service->history($refund->fresh()))->pluck('action')->all();
        $this->assertContains('refund.created', $actions);
        $this->assertContains('refund.submitted', $actions);
        $this->assertContains('refund.approved', $actions);
    }

    public function test_authenticated_paystack_refund_webhook_completes_once_and_rejects_bad_signatures(): void
    {
        $gateway = $this->gatewayAccount();
        Http::fake([
            'api.paystack.co/refund/*' => Http::response(['status' => true, 'data' => ['id' => 98765, 'status' => 'processed']], 200),
            'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 98765, 'status' => 'pending']], 200),
        ]);
        $payment = $this->payment('50.00', 'S4C-PAY-14', ['provider' => 'paystack', 'payment_method' => 'gateway', 'provider_reference' => 'PSK-TX-14']);
        $service = app(RefundService::class);
        $refund = $this->approvedRefund($service, $payment, '50.00', 's4c-refund-0121');
        $processing = $service->process($this->processor, $refund);

        $payload = json_encode(['event' => 'refund.processed', 'data' => ['id' => 98765, 'status' => 'processed', 'amount' => 5000, 'currency' => 'GHS']]);
        $this->call('POST', '/api/v1/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, $gateway->secret_key),
        ], $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame(Refund::STATUS_COMPLETED, $processing->fresh()->status);

        $this->call('POST', '/api/v1/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, $gateway->secret_key),
        ], $payload)->assertOk();
        $this->assertSame(1, AdminAuditLog::where('action', 'refund.completed')->count());

        $this->call('POST', '/api/v1/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => str_repeat('a', 128),
        ], $payload)->assertStatus(401);
    }

    public function test_refund_endpoints_enforce_permissions_and_ownership_boundaries(): void
    {
        $payment = $this->payment('60.00', 'S4C-PAY-15');

        $outsider = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/admin/refunds')->assertForbidden();
        $this->postJson('/api/v1/admin/refunds', $this->draftInput($payment, '10.00', 's4c-refund-0131'))->assertForbidden();

        Sanctum::actingAs($this->requester);
        $created = $this->postJson('/api/v1/admin/refunds', $this->draftInput($payment, '60.00', 's4c-refund-0132'))->assertCreated();
        $refundId = $created->json('data.id');
        $this->getJson('/api/v1/admin/refunds')->assertOk();
        $this->getJson("/api/v1/admin/refunds/{$refundId}")->assertOk()->assertJsonPath('data.status', Refund::STATUS_DRAFT);
        $this->postJson("/api/v1/admin/refunds/{$refundId}/approve")->assertForbidden();
        $this->postJson("/api/v1/admin/refunds/{$refundId}/submit")->assertOk();

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/admin/refunds/{$refundId}/approve")->assertOk()->assertJsonPath('data.status', Refund::STATUS_APPROVED);
        $this->getJson("/api/v1/admin/refunds/{$refundId}/history")->assertOk()->assertJsonFragment(['action' => 'refund.approved']);
    }

    public function test_stage4c_permissions_are_granular_and_not_granted_to_ordinary_admin(): void
    {
        $this->assertSame(16, Permission::where('slug', 'like', 'financials.refunds.%')->orWhere('slug', 'like', 'financials.credit_notes.%')->count());

        // Viewing follows the existing transaction register; a read-only
        // finance role must not inherit any mutating refund capability.
        $viewerRole = Role::create(['name' => 'Finance Viewer', 'slug' => 'finance-viewer']);
        $viewerRole->permissions()->sync(Permission::whereIn('slug', [
            'financials.transactions.view', 'financials.refunds.view', 'financials.credit_notes.view',
        ])->pluck('id'));
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->roles()->attach($viewerRole);

        $this->assertTrue($viewer->hasPermission('financials.refunds.view'));
        $this->assertTrue($viewer->hasPermission('financials.credit_notes.view'));
        $this->assertFalse($viewer->hasPermission('financials.refunds.create'));
        $this->assertFalse($viewer->hasPermission('financials.refunds.approve'));
        $this->assertFalse($viewer->hasPermission('financials.credit_notes.issue'));

        // The existing ordinary admin role deliberately holds no financial
        // mutation capability after the migration.
        $adminRole = Role::where('slug', 'admin')->first();
        $this->assertNotNull($adminRole);
        $this->assertFalse($adminRole->permissions()->where('slug', 'like', 'financials.%')->exists());
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);
        $this->assertFalse($admin->hasPermission('financials.refunds.approve'));
        $this->assertFalse($admin->hasPermission('financials.credit_notes.apply'));
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'Role '.uniqid(), 'slug' => 'role-'.uniqid()]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id'));
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function payment(string $amount, string $reference, array $overrides = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'order_id' => null,
            'customer_id' => $this->customer->id,
            'organisation_id' => null,
            'payment_purpose' => 'invoice',
            'transaction_reference' => $reference,
            'provider' => 'cash',
            'payment_method' => 'cash',
            'amount' => $amount,
            'currency' => 'GHS',
            'status' => 'succeeded',
            'verified_at' => now(),
            'received_at' => now(),
            'reporting_date' => now()->toDateString(),
        ], $overrides));
    }

    private function invoice(Customer $customer, string $total, string $number): CommercialDocument
    {
        return CommercialDocument::create([
            'document_number' => $number, 'document_type' => CommercialDocument::TYPE_INVOICE,
            'status' => CommercialDocument::STATUS_ISSUED, 'customer_id' => $customer->id,
            'currency' => 'GHS', 'subtotal' => $total, 'discount_amount' => '0.00', 'tax_amount' => '0.00',
            'total' => $total, 'issue_date' => today(), 'issued_at' => now(),
        ]);
    }

    private function draftInput(PaymentTransaction $payment, string $amount, string $reference): array
    {
        return [
            'payment_transaction_id' => $payment->id,
            'amount' => $amount,
            'refund_method' => Refund::METHOD_ORIGINAL,
            'reason' => 'Customer requested a refund for this payment',
            'operation_reference' => $reference,
        ];
    }

    private function approvedRefund(RefundService $service, PaymentTransaction $payment, string $amount, string $reference, string $method = Refund::METHOD_ORIGINAL): Refund
    {
        $input = $this->draftInput($payment, $amount, $reference);
        $input['refund_method'] = $method;
        $refund = $service->create($this->requester, $input);
        $service->submit($this->requester, $refund);
        return $service->approve($this->approver, $refund->fresh());
    }

    private function gatewayAccount(): PaymentGateway
    {
        return PaymentGateway::create([
            'provider' => 'paystack', 'display_name' => 'Paystack Test', 'public_key' => 'pk_test_stage4c',
            'secret_key' => 'sk_test_stage4c', 'is_enabled' => true, 'is_default' => true,
        ]);
    }
}
