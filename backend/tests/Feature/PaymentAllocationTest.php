<?php

namespace Tests\Feature;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Services\CommercialDocumentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    private User $financeUser;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => 'Finance Allocator', 'slug' => 'finance-allocator']);
        $permissions = Permission::whereIn('slug', [
            'financials.allocations.view', 'financials.allocations.manage', 'financials.allocations.reverse', 'financials.invoice_balances.view',
        ])->pluck('id');
        $role->permissions()->sync($permissions);
        $this->financeUser = User::factory()->create(['is_active' => true]);
        $this->financeUser->roles()->attach($role);
        $this->customer = Customer::create([
            'name' => 'Invoice Payer', 'phone' => '0200000777', 'email' => 'payer@example.test',
            'password' => 'test-password', 'is_active' => true,
        ]);
        // Customer invoice access requires both contact channels verified.
        $this->customer->forceFill(['email_verified_at' => now(), 'phone_verified_at' => now()])->save();
    }

    public function test_partial_and_split_allocations_update_derived_balances_and_are_idempotent(): void
    {
        $first = $this->invoice('5000.00', 'INV-ALLOC-1');
        $second = $this->invoice('3000.00', 'INV-ALLOC-2');
        $payment = $this->invoicePayment('4000.00', 'PAY-ALLOC-1');
        $service = app(PaymentAllocationService::class);

        $one = $service->allocate($payment->id, [['commercial_document_id' => $first->id, 'amount' => '2000.00']], 'alloc-operation-0001', $this->financeUser);
        $replay = $service->allocate($payment->id, [['commercial_document_id' => $first->id, 'amount' => '2000.00']], 'alloc-operation-0001', $this->financeUser);
        $this->assertSame($one['id'], $replay['id']);
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame('3000.00', $service->balance($first)['outstanding_balance']);

        $split = $service->allocate($payment->id, [
            ['commercial_document_id' => $first->id, 'amount' => '1000.00'],
            ['commercial_document_id' => $second->id, 'amount' => '1000.00'],
        ], 'alloc-operation-0002', $this->financeUser);

        $this->assertCount(2, $split['allocations']);
        $this->assertSame('2000.00', $service->balance($first)['outstanding_balance']);
        $this->assertSame('2000.00', $service->balance($second)['outstanding_balance']);
        $this->assertCount(0, $service->eligiblePayments()->items());
    }

    public function test_unverified_order_purpose_and_payer_or_currency_mismatch_are_rejected(): void
    {
        $invoice = $this->invoice('100.00', 'INV-ALLOC-3');
        $service = app(PaymentAllocationService::class);
        $pending = $this->invoicePayment('100.00', 'PAY-PENDING', ['status' => 'pending', 'verified_at' => null]);
        try {
            $service->allocate($pending->id, [['commercial_document_id' => $invoice->id, 'amount' => '10.00']], 'alloc-operation-0003', $this->financeUser);
            $this->fail('A pending payment must not be allocated.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('verified', $exception->getMessage());
        }

        $otherCustomer = Customer::create(['name' => 'Other', 'phone' => '0200000888', 'email' => 'other@example.test', 'password' => 'test-password', 'is_active' => true]);
        $mismatch = $this->invoicePayment('100.00', 'PAY-MISMATCH', ['customer_id' => $otherCustomer->id]);
        try {
            $service->allocate($mismatch->id, [['commercial_document_id' => $invoice->id, 'amount' => '10.00']], 'alloc-operation-0004', $this->financeUser);
            $this->fail('A payer mismatch must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('payer', $exception->getMessage());
        }

        $order = Order::create([
            'order_number' => 'ALLOC-ORDER-1', 'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'Payer', 'customer_phone' => '0200000777', 'status' => Order::STATUS_COMPLETED,
            'subtotal' => '100.00', 'delivery_fee' => '0.00', 'total' => '100.00',
            'payment_method' => 'cash', 'payment_status' => Order::PAYMENT_STATUS_PAID,
        ]);
        $orderPayment = PaymentTransaction::create([
            'order_id' => $order->id, 'transaction_reference' => 'ORDINARY-ORDER-1', 'payment_purpose' => 'order',
            'payment_method' => 'cash', 'amount' => '100.00', 'currency' => 'GHS', 'status' => 'succeeded',
            'verified_at' => now(), 'received_at' => now(), 'reporting_date' => now()->toDateString(),
        ]);
        try {
            $service->allocate($orderPayment->id, [['commercial_document_id' => $invoice->id, 'amount' => '10.00']], 'alloc-operation-0005', $this->financeUser);
            $this->fail('Order payments must remain independent of invoice allocations.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('invoice-purpose', $exception->getMessage());
        }
    }

    public function test_overallocation_quotation_and_draft_invoice_are_rejected(): void
    {
        $invoice = $this->invoice('20.00', 'INV-ALLOC-4');
        $quote = $this->invoice('20.00', 'QT-ALLOC-1', ['document_type' => 'quotation']);
        $draft = $this->invoice('20.00', 'INV-ALLOC-5', ['status' => 'draft', 'issued_at' => null]);
        $payment = $this->invoicePayment('50.00', 'PAY-ALLOC-2');
        $service = app(PaymentAllocationService::class);

        foreach ([[$invoice, '21.00'], [$quote, '1.00'], [$draft, '1.00']] as [$document, $amount]) {
            try {
                $service->allocate($payment->id, [['commercial_document_id' => $document->id, 'amount' => $amount]], 'alloc-operation-'.str_pad((string) $document->id, 4, '0', STR_PAD_LEFT), $this->financeUser);
                $this->fail('An invalid allocation must be rejected.');
            } catch (DomainException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_reversal_preserves_original_allocation_and_restores_invoice_balance(): void
    {
        $invoice = $this->invoice('100.00', 'INV-ALLOC-6');
        $payment = $this->invoicePayment('100.00', 'PAY-ALLOC-3');
        $service = app(PaymentAllocationService::class);
        $result = $service->allocate($payment->id, [['commercial_document_id' => $invoice->id, 'amount' => '60.00']], 'alloc-operation-0006', $this->financeUser);
        $allocation = PaymentAllocation::findOrFail($result['allocations'][0]['id']);

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/v1/admin/financials/payment-allocations/{$allocation->id}/reverse", [
            'operation_reference' => 'customer-reversal-01', 'reason' => 'Not a staff actor',
        ])->assertForbidden();
        Sanctum::actingAs($this->financeUser);
        $this->postJson("/api/v1/admin/financials/payment-allocations/{$allocation->id}/reverse", [
            'operation_reference' => 'reverse-operation-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/financials/payment-allocations/{$allocation->id}/reverse", [
            'operation_reference' => 'reverse-operation-01', 'reason' => 'Applied to the wrong invoice',
        ])->assertOk();
        $this->postJson("/api/v1/admin/financials/payment-allocations/{$allocation->id}/reverse", [
            'operation_reference' => 'reverse-operation-02', 'reason' => 'Second attempt',
        ])->assertConflict();

        $this->assertSame('100.00', $service->balance($invoice)['outstanding_balance']);
        $this->assertNotNull($allocation->fresh()->reversal);
        $this->assertSame('100.00', $service->eligiblePayments()->items()[0]['unallocated_amount']);
    }

    public function test_multiple_receipts_can_settle_one_invoice_and_organisation_payer_is_scoped(): void
    {
        $invoice = $this->invoice('30.00', 'INV-ALLOC-8');
        $service = app(PaymentAllocationService::class);
        foreach ([['PAY-ALLOC-5', '10.00'], ['PAY-ALLOC-6', '20.00']] as [$reference, $amount]) {
            $payment = $this->invoicePayment($amount, $reference);
            $service->allocate($payment->id, [['commercial_document_id' => $invoice->id, 'amount' => $amount]], 'multi-payment-'.strtolower($reference), $this->financeUser);
        }
        $this->assertSame('0.00', $service->balance($invoice)['outstanding_balance']);
        $this->assertSame('fully_paid', $service->balance($invoice)['payment_state']);

        $organisation = Organisation::create([
            'reference' => 'ORG-ALLOC-1', 'owner_customer_id' => $this->customer->id, 'name' => 'Invoice Organisation', 'status' => 'active',
        ]);
        $orgInvoice = $this->invoice('50.00', 'INV-ALLOC-9', ['organisation_id' => $organisation->id]);
        $orgPayment = $this->invoicePayment('50.00', 'PAY-ALLOC-ORG', ['customer_id' => null, 'organisation_id' => $organisation->id]);
        $service->allocate($orgPayment->id, [['commercial_document_id' => $orgInvoice->id, 'amount' => '50.00']], 'org-alloc-operation-01', $this->financeUser);
        $this->assertSame('0.00', $service->balance($orgInvoice)['outstanding_balance']);
    }

    public function test_active_allocation_prevents_invoice_void_until_reversed(): void
    {
        $invoice = $this->invoice('100.00', 'INV-ALLOC-10');
        $payment = $this->invoicePayment('25.00', 'PAY-ALLOC-VOID');
        $result = app(PaymentAllocationService::class)->allocate($payment->id, [['commercial_document_id' => $invoice->id, 'amount' => '25.00']], 'void-check-operation-1', $this->financeUser);

        try {
            app(CommercialDocumentService::class)->staffTransition($this->financeUser, $invoice, 'void', 'Correction', Request::create('/'));
            $this->fail('An invoice with an active allocation must not be voided.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Reverse active', $exception->getMessage());
        }

        app(PaymentAllocationService::class)->reverse(PaymentAllocation::findOrFail($result['allocations'][0]['id']), 'void-check-reversal-1', 'Incorrect allocation', $this->financeUser);
        app(CommercialDocumentService::class)->staffTransition($this->financeUser, $invoice->fresh(), 'void', 'Correction', Request::create('/'));
        $this->assertSame(CommercialDocument::STATUS_VOIDED, $invoice->fresh()->status);
    }

    public function test_customer_invoice_view_shows_derived_payment_state_without_financial_mutations(): void
    {
        $invoice = $this->invoice('75.00', 'INV-ALLOC-7');
        $payment = $this->invoicePayment('25.00', 'PAY-ALLOC-4');
        app(PaymentAllocationService::class)->allocate($payment->id, [['commercial_document_id' => $invoice->id, 'amount' => '25.00']], 'alloc-operation-0007', $this->financeUser);

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/customer/invoices/{$invoice->id}")
            ->assertOk()->assertJsonPath('data.payment_summary.payment_state', 'partially_paid')
            ->assertJsonPath('data.payment_summary.outstanding_balance', '50.00')
            ->assertJsonPath('data.payment_summary.payments.0.transaction_reference', 'PAY-ALLOC-4');
        $this->postJson('/api/v1/admin/financials/payment-allocations', [])->assertForbidden();
    }

    public function test_server_preview_is_read_only_and_allocation_captures_actor_snapshot(): void
    {
        $invoice = $this->invoice('90.00', 'INV-PREVIEW-1');
        $payment = $this->invoicePayment('100.00', 'PAY-PREVIEW-1');
        Sanctum::actingAs($this->financeUser);

        $this->postJson('/api/v1/admin/financials/payment-allocations/preview', [
            'payment_transaction_id' => $payment->id,
            'allocations' => [['commercial_document_id' => $invoice->id, 'amount' => '35.00']],
        ])->assertOk()
            ->assertJsonPath('data.payment.original_amount', '100.00')
            ->assertJsonPath('data.payment.available_amount', '100.00')
            ->assertJsonPath('data.invoices.0.resulting_balance', '55.00')
            ->assertJsonPath('data.remaining_unallocated', '65.00');
        $this->assertSame(0, PaymentAllocation::count());

        $created = $this->postJson('/api/v1/admin/financials/payment-allocations', [
            'payment_transaction_id' => $payment->id,
            'operation_reference' => 'preview-actor-operation-1',
            'actor_name_snapshot' => 'Forged Client Actor',
            'allocations' => [['commercial_document_id' => $invoice->id, 'amount' => '35.00']],
        ])->assertCreated();
        $operation = \App\Models\PaymentAllocationOperation::findOrFail($created->json('data.id'));
        $this->assertSame($this->financeUser->id, $operation->actor_user_id_snapshot);
        $this->assertSame($this->financeUser->name, $operation->actor_name_snapshot);
        $this->assertSame('user:'.$this->financeUser->id, $operation->actor_reference_snapshot);
    }

    public function test_allocation_reversal_route_requires_its_separate_permission(): void
    {
        $invoice = $this->invoice('90.00', 'INV-REVERSE-AUTH-1');
        $payment = $this->invoicePayment('40.00', 'PAY-REVERSE-AUTH-1');
        $result = app(PaymentAllocationService::class)->allocate(
            $payment->id,
            [['commercial_document_id' => $invoice->id, 'amount' => '20.00']],
            'reverse-auth-operation-1',
            $this->financeUser,
        );

        $role = $this->financeUser->roles()->firstOrFail();
        $reversePermission = Permission::where('slug', 'financials.allocations.reverse')->firstOrFail();
        $role->permissions()->detach($reversePermission);
        $this->financeUser->unsetRelation('roles');
        Sanctum::actingAs($this->financeUser);

        $this->postJson('/api/v1/admin/financials/payment-allocations/'.$result['allocations'][0]['id'].'/reverse', [
            'operation_reference' => 'reverse-auth-attempt-1',
            'reason' => 'Test authorization boundary',
        ])->assertForbidden();
        $this->assertDatabaseMissing('payment_allocation_reversals', ['payment_allocation_id' => $result['allocations'][0]['id']]);
    }

    public function test_operation_reference_reuse_with_different_payload_is_a_controlled_conflict(): void
    {
        $invoice = $this->invoice('100.00', 'INV-IDEMPOTENCY-1');
        $firstPayment = $this->invoicePayment('100.00', 'PAY-IDEMPOTENCY-1');
        $secondPayment = $this->invoicePayment('100.00', 'PAY-IDEMPOTENCY-2');
        $reference = 'same-operation-reference-1';
        $service = app(PaymentAllocationService::class);
        $service->allocate($firstPayment->id, [['commercial_document_id' => $invoice->id, 'amount' => '20.00']], $reference, $this->financeUser);

        Sanctum::actingAs($this->financeUser);
        $this->postJson('/api/v1/admin/financials/payment-allocations', [
            'payment_transaction_id' => $secondPayment->id,
            'operation_reference' => $reference,
            'allocations' => [['commercial_document_id' => $invoice->id, 'amount' => '20.00']],
        ])->assertConflict()->assertJsonPath('success', false);
        $this->assertSame(1, \App\Models\PaymentAllocationOperation::where('operation_reference', $reference)->count());
    }

    public function test_only_the_operation_reference_unique_constraint_is_classified_as_idempotency_conflict(): void
    {
        $service = app(PaymentAllocationService::class);
        $method = new \ReflectionMethod(PaymentAllocationService::class, 'isOperationReferenceUniqueConflict');
        $uniqueDriverException = new \PDOException('Duplicate entry for payment_allocation_operations_operation_reference_unique');
        $uniqueDriverException->errorInfo = ['23000', 1062, 'Duplicate entry for payment_allocation_operations_operation_reference_unique'];
        $unique = new QueryException('mysql', 'insert operation reference', [], $uniqueDriverException);
        $foreignKeyDriverException = new \PDOException('Cannot add or update a child row: a foreign key constraint fails');
        $foreignKeyDriverException->errorInfo = ['23000', 1452, 'Cannot add or update a child row'];
        $foreignKey = new QueryException('mysql', 'insert unrelated foreign key', [], $foreignKeyDriverException);

        $this->assertTrue($method->invoke($service, $unique));
        $this->assertFalse($method->invoke($service, $foreignKey));
    }

    public function test_organisation_invoice_financial_summary_requires_explicit_membership_grant(): void
    {
        $organisation = Organisation::create([
            'reference' => 'ORG-FIN-VIEW-1', 'owner_customer_id' => $this->customer->id, 'name' => 'Financial Visibility Org', 'status' => 'active',
        ]);
        $ownerMembership = OrganisationMembership::create([
            'organisation_id' => $organisation->id, 'customer_id' => $this->customer->id,
            'role' => OrganisationMembership::ROLE_OWNER, 'status' => OrganisationMembership::STATUS_ACTIVE,
        ]);
        $invoice = $this->invoice('100.00', 'INV-ORG-FIN-1', ['organisation_id' => $organisation->id]);
        $payment = $this->invoicePayment('30.00', 'PAY-ORG-FIN-1', ['customer_id' => null, 'organisation_id' => $organisation->id]);
        app(PaymentAllocationService::class)->allocate($payment->id, [['commercial_document_id' => $invoice->id, 'amount' => '30.00']], 'org-fin-view-operation-1', $this->financeUser);

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/customer/invoices/{$invoice->id}")->assertOk()->assertJsonMissingPath('data.payment_summary');
        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}/members/{$ownerMembership->id}/financial-access", ['enabled' => true])
            ->assertOk()->assertJsonPath('data.permission', 'financials.view')->assertJsonPath('data.enabled', true);
        $this->getJson("/api/v1/customer/invoices/{$invoice->id}")
            ->assertOk()->assertJsonPath('data.payment_summary.outstanding_balance', '70.00')
            ->assertJsonPath('data.payment_summary.payments.0.transaction_reference', 'PAY-ORG-FIN-1');

        $otherOwner = Customer::create(['name' => 'Other Org Owner', 'phone' => '0200000991', 'email' => 'other-org@example.test', 'password' => 'test-password', 'is_active' => true]);
        $otherOrg = Organisation::create(['reference' => 'ORG-FIN-VIEW-2', 'owner_customer_id' => $otherOwner->id, 'name' => 'Other Organisation', 'status' => 'active']);
        $foreignInvoice = $this->invoice('25.00', 'INV-ORG-FIN-FOREIGN', ['customer_id' => $otherOwner->id, 'organisation_id' => $otherOrg->id]);
        $this->getJson("/api/v1/customer/invoices/{$foreignInvoice->id}")->assertNotFound();
    }

    public function test_financial_actor_snapshot_survives_live_user_deletion(): void
    {
        $invoice = $this->invoice('50.00', 'INV-ACTOR-RETAIN-1');
        $payment = $this->invoicePayment('50.00', 'PAY-ACTOR-RETAIN-1');
        $result = app(PaymentAllocationService::class)->allocate($payment->id, [['commercial_document_id' => $invoice->id, 'amount' => '15.00']], 'actor-retention-operation-1', $this->financeUser);
        $allocation = PaymentAllocation::findOrFail($result['allocations'][0]['id']);
        app(PaymentAllocationService::class)->reverse($allocation, 'actor-retention-reversal-1', 'Recorded against the incorrect invoice', $this->financeUser);

        $originalName = $this->financeUser->name;
        $originalId = $this->financeUser->id;
        $this->financeUser->delete();
        $history = app(PaymentAllocationService::class)->summaryForDocument($invoice)['payments'][0];
        $this->assertSame($originalName, $history['allocated_by']);
        $this->assertSame($originalName, $history['reversal']['reversed_by']);
        $this->assertSame($originalId, $allocation->operation->fresh()->actor_user_id_snapshot);
        $this->assertSame($originalId, $allocation->fresh()->reversal->actor_user_id_snapshot);
    }

    private function invoice(string $total, string $number, array $overrides = []): CommercialDocument
    {
        return CommercialDocument::create(array_merge([
            'document_number' => $number,
            'document_type' => CommercialDocument::TYPE_INVOICE,
            'status' => CommercialDocument::STATUS_ISSUED,
            'customer_id' => $this->customer->id,
            'currency' => 'GHS',
            'subtotal' => $total,
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => $total,
            'issue_date' => today(),
            'issued_at' => now(),
        ], $overrides));
    }

    private function invoicePayment(string $amount, string $reference, array $overrides = []): PaymentTransaction
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
}
