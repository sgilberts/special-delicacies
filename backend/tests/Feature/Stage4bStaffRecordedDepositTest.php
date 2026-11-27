<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCreditLedgerEntry;
use App\Models\Organisation;
use App\Models\PaymentReceiptIntent;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffDepositRecord;
use App\Models\User;
use App\Services\CustomerCreditService;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stage 4B — staff-recorded deposits with independent, evidence-based
 * verification. No gateway is involved: these are receipts for money already
 * received outside the online checkout.
 */
class Stage4bStaffRecordedDepositTest extends TestCase
{
    use RefreshDatabase;

    private Customer $payer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payer = Customer::create([
            'name' => 'Staff Deposit Payer', 'phone' => '0200001001', 'email' => 'staff-deposit@example.test',
            'password' => 'customer-password', 'is_active' => true,
        ]);
    }

    public function test_authorised_staff_can_record_a_received_deposit_pending_verification(): void
    {
        $recorder = $this->financeStaff();

        $response = $this->record($recorder)->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.intent', 'deposit')
            ->assertJsonPath('data.amount', '120.00')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.recorded_by.id', $recorder->id);

        $record = StaffDepositRecord::sole();
        $this->assertSame(StaffDepositRecord::STATUS_PENDING, $record->status);
        $this->assertSame($recorder->id, (int) $record->recorded_by);
        $this->assertSame($recorder->name, $record->recorded_by_name_snapshot);
        $this->assertNotNull($record->recorded_at);
        $this->assertNull($record->verified_by);

        $transaction = $record->paymentTransaction;
        $this->assertSame('pending', $transaction->status);
        $this->assertNull($transaction->order_id);
        $this->assertSame('invoice', $transaction->payment_purpose);
        $this->assertSame($this->payer->id, (int) $transaction->customer_id);
        $this->assertSame('120.00', $transaction->amount);
        $this->assertNull($transaction->verified_at);
        $this->assertNull($transaction->received_at);
        // Recording a reference is not proof of receipt.
        $this->assertSame(0, PaymentReceiptIntent::count());
        $this->assertSame(0, CustomerCreditLedgerEntry::count());
        $this->assertSame($response->json('data.payment_reference'), $transaction->transaction_reference);
    }

    public function test_unauthorised_staff_cannot_record_or_read_deposits(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $this->actingAs($outsider, 'sanctum')->postJson('/api/v1/admin/deposits', $this->payload())->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/admin/deposits')->assertForbidden();
        $this->assertSame(0, StaffDepositRecord::count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_ordinary_admin_does_not_automatically_receive_finance_permissions(): void
    {
        app(RoleAndPermissionSeeder::class)->run();
        $adminRole = Role::where('slug', 'admin')->firstOrFail();
        $this->assertFalse($adminRole->permissions()->whereIn('slug', [
            'financials.deposits.view', 'financials.deposits.manage',
        ])->exists());

        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/deposits', $this->payload())->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/deposits')->assertForbidden();
    }

    public function test_recording_staff_cannot_verify_their_own_deposit(): void
    {
        $recorder = $this->financeStaff();
        $id = $this->record($recorder)->assertCreated()->json('data.id');

        $this->actingAs($recorder, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", ['reason' => 'I confirm my own receipt'])
            ->assertStatus(409);

        $record = StaffDepositRecord::sole();
        $this->assertSame(StaffDepositRecord::STATUS_PENDING, $record->status);
        $this->assertSame('pending', $record->paymentTransaction->status);
        $this->assertSame(0, PaymentReceiptIntent::count());
    }

    public function test_independent_authorised_verifier_completes_the_receipt(): void
    {
        $recorder = $this->financeStaff();
        $verifier = $this->financeStaff();
        $id = $this->record($recorder)->assertCreated()->json('data.id');

        $this->actingAs($verifier, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", [
            'reason' => 'Bank statement line matched the receipt reference.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.payment_status', 'succeeded')
            ->assertJsonPath('data.verified_by.id', $verifier->id)
            ->assertJsonPath('data.decision_reason', 'Bank statement line matched the receipt reference.');

        $record = StaffDepositRecord::sole();
        $this->assertSame(StaffDepositRecord::STATUS_VERIFIED, $record->status);
        $this->assertSame($verifier->id, (int) $record->verified_by);
        $this->assertSame($verifier->name, $record->verified_by_name_snapshot);
        $this->assertNotNull($record->verified_at);

        $transaction = $record->paymentTransaction->fresh();
        $this->assertSame('succeeded', $transaction->status);
        $this->assertNotNull($transaction->verified_at);
        $this->assertNotNull($transaction->received_at);

        $intent = PaymentReceiptIntent::sole();
        $this->assertSame('deposit', $intent->intent);
        $this->assertSame($transaction->id, (int) $intent->payment_transaction_id);
        $this->assertSame($verifier->id, (int) $intent->actor_user_id);

        // Only now is the receipt eligible for the existing credit designation.
        $eligible = app(CustomerCreditService::class)->eligibleReceipts();
        $this->assertCount(1, $eligible->items());
        $this->assertSame($transaction->transaction_reference, $eligible->items()[0]['reference']);
    }

    public function test_unauthorised_verifier_cannot_approve_a_deposit(): void
    {
        $recorder = $this->financeStaff();
        $id = $this->record($recorder)->assertCreated()->json('data.id');
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", ['reason' => 'Not my job'])
            ->assertForbidden();
        $this->assertSame(StaffDepositRecord::STATUS_PENDING, StaffDepositRecord::sole()->status);
        $this->assertSame(0, PaymentReceiptIntent::count());
    }

    public function test_pending_staff_deposit_cannot_be_credited(): void
    {
        $recorder = $this->financeStaff();
        $this->record($recorder)->assertCreated();
        $transaction = PaymentTransaction::sole();

        try {
            app(CustomerCreditService::class)->classifyReceipt($transaction->id, 'deposit', 'staff-deposit-intent-1', 'Too early', $recorder);
            $this->fail('A pending staff deposit must not be classified.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('verified', $exception->getMessage());
        }

        try {
            app(CustomerCreditService::class)->designateCredit($transaction->id, '10.00', 'staff-deposit-credit-1', 'Too early', $recorder);
            $this->fail('A pending staff deposit must not become credit.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('verified', $exception->getMessage());
        }
        $this->assertSame(0, CustomerCreditLedgerEntry::count());
    }

    public function test_rejected_deposit_cannot_be_credited(): void
    {
        $recorder = $this->financeStaff();
        $verifier = $this->financeStaff();
        $id = $this->record($recorder)->assertCreated()->json('data.id');

        $this->actingAs($verifier, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/reject", [
            'reason' => 'No matching bank statement entry was found.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $record = StaffDepositRecord::sole();
        $this->assertSame(StaffDepositRecord::STATUS_REJECTED, $record->status);
        $this->assertSame('cancelled', $record->paymentTransaction->fresh()->status);
        $this->assertSame(0, PaymentReceiptIntent::count());

        try {
            app(CustomerCreditService::class)->designateCredit($record->payment_transaction_id, '10.00', 'staff-deposit-credit-2', 'Rejected', $verifier);
            $this->fail('A rejected deposit must never become credit.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('verified', $exception->getMessage());
        }
        $this->assertSame(0, CustomerCreditLedgerEntry::count());
    }

    public function test_missing_or_invalid_evidence_is_rejected(): void
    {
        $recorder = $this->financeStaff();
        foreach ([
            ['evidence' => 'short'],
            ['external_reference' => 'x'],
            ['payment_method' => 'crypto'],
            ['amount' => '0'],
            ['payer_type' => 'customer', 'customer_id' => 999999],
            ['payer_type' => 'organisation', 'organisation_id' => 999999],
        ] as $override) {
            $this->record($recorder, $override)->assertStatus(422);
        }
        $this->assertSame(0, StaffDepositRecord::count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_verification_records_actor_timestamps_and_audit_trail(): void
    {
        $recorder = $this->financeStaff();
        $verifier = $this->financeStaff();
        $id = $this->record($recorder)->assertCreated()->json('data.id');
        $this->actingAs($verifier, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", [
            'reason' => 'Receipt photo and bank line agree.',
        ])->assertOk();

        $this->actingAs($verifier, 'sanctum')->getJson("/api/v1/admin/deposits/{$id}")->assertOk()
            ->assertJsonPath('data.recorded_by.id', $recorder->id)
            ->assertJsonPath('data.recorded_by.name', $recorder->name)
            ->assertJsonPath('data.verified_by.id', $verifier->id)
            ->assertJsonPath('data.verified_by.name', $verifier->name)
            ->assertJsonPath('data.decision_reason', 'Receipt photo and bank line agree.')
            ->assertJsonPath('data.external_reference', 'BANK-REF-1001')
            ->assertJsonPath('data.evidence', 'Bank transfer received and matched to the customer account.');

        $record = StaffDepositRecord::sole();
        $this->assertNotNull($record->recorded_at);
        $this->assertNotNull($record->verified_at);
        $this->assertTrue($record->verified_at->greaterThanOrEqualTo($record->recorded_at));
    }

    public function test_repeated_verification_requests_are_idempotent(): void
    {
        $recorder = $this->financeStaff();
        $verifier = $this->financeStaff();
        $id = $this->record($recorder)->assertCreated()->json('data.id');

        $this->actingAs($verifier, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", ['reason' => 'Confirmed against bank line.'])->assertOk();
        $this->actingAs($verifier, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", ['reason' => 'Confirmed against bank line.'])->assertOk();

        $this->assertSame(1, PaymentReceiptIntent::count());
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame(StaffDepositRecord::STATUS_VERIFIED, StaffDepositRecord::sole()->status);
        $this->assertSame($verifier->id, (int) StaffDepositRecord::sole()->verified_by);
    }

    public function test_duplicate_external_reference_is_rejected(): void
    {
        $recorder = $this->financeStaff();
        $this->record($recorder)->assertCreated();
        $this->record($recorder)->assertStatus(422);
        $this->assertSame(1, StaffDepositRecord::count());
        $this->assertSame(1, PaymentTransaction::count());

        // The same reference through a different channel is a different receipt.
        $this->record($recorder, ['payment_method' => 'cheque'])->assertCreated();
        $this->assertSame(2, PaymentTransaction::count());
    }

    public function test_staff_record_cannot_create_a_duplicate_payment_transaction(): void
    {
        $recorder = $this->financeStaff();
        $verifier = $this->financeStaff();
        $id = $this->record($recorder)->assertCreated()->json('data.id');
        $this->actingAs($verifier, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", ['reason' => 'Verified once.'])->assertOk();
        $this->actingAs($verifier, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", ['reason' => 'Verified once.'])->assertOk();

        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame(1, StaffDepositRecord::count());
        $this->assertSame(1, PaymentReceiptIntent::count());
    }

    public function test_organisation_payer_is_supported_and_keeps_one_payer(): void
    {
        $recorder = $this->financeStaff();
        $organisation = Organisation::create([
            'reference' => 'STAFF-DEP-ORG-1', 'owner_customer_id' => $this->payer->id,
            'name' => 'Staff Deposit Organisation', 'status' => 'active',
        ]);

        $id = $this->record($recorder, [
            'payer_type' => 'organisation', 'customer_id' => null, 'organisation_id' => $organisation->id,
        ])->assertCreated()->assertJsonPath('data.payer.type', 'organisation')->json('data.id');

        $transaction = PaymentTransaction::sole();
        $this->assertNull($transaction->customer_id);
        $this->assertSame($organisation->id, (int) $transaction->organisation_id);

        $verifier = $this->financeStaff();
        $this->actingAs($verifier, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", ['reason' => 'Organisation receipt confirmed.'])->assertOk();
        $this->assertSame('succeeded', $transaction->fresh()->status);
    }

    public function test_pending_queue_and_status_filters_are_permission_scoped(): void
    {
        $recorder = $this->financeStaff();
        $verifier = $this->financeStaff();
        $id = $this->record($recorder)->assertCreated()->json('data.id');

        $this->actingAs($verifier, 'sanctum')->getJson('/api/v1/admin/deposits?status=pending')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->actingAs($verifier, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", ['reason' => 'Confirmed.'])->assertOk();
        $this->actingAs($verifier, 'sanctum')->getJson('/api/v1/admin/deposits?status=pending')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($verifier, 'sanctum')->getJson('/api/v1/admin/deposits?status=verified')->assertOk()->assertJsonCount(1, 'data');

        $viewer = $this->financeStaff(viewOnly: true);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/admin/deposits')->assertOk();
        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/admin/deposits', $this->payload())->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/admin/deposits/{$id}/verify", ['reason' => 'Viewer cannot verify.'])->assertForbidden();
    }

    private function record(User $actor, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->postJson('/api/v1/admin/deposits', array_merge($this->payload(), $overrides));
    }

    private function payload(): array
    {
        return [
            'payer_type' => 'customer',
            'customer_id' => $this->payer->id,
            'intent' => 'deposit',
            'amount' => '120.00',
            'currency' => 'GHS',
            'payment_method' => 'bank_transfer',
            'external_reference' => 'BANK-REF-1001',
            'evidence' => 'Bank transfer received and matched to the customer account.',
        ];
    }

    private function financeStaff(bool $viewOnly = false): User
    {
        $role = Role::firstOrCreate(
            ['slug' => $viewOnly ? 'deposit-viewer-test' : 'deposit-finance-test'],
            ['name' => $viewOnly ? 'Deposit Viewer Test' : 'Deposit Finance Test'],
        );
        $permissions = [Permission::firstOrCreate(
            ['slug' => 'financials.deposits.view'],
            ['name' => 'View Deposit and Advance Receipts', 'description' => 'View deposit receipts'],
        )];
        if (! $viewOnly) {
            $permissions[] = Permission::firstOrCreate(
                ['slug' => 'financials.deposits.manage'],
                ['name' => 'Manage Deposit and Advance Receipts', 'description' => 'Record and verify deposit receipts'],
            );
        }
        $role->permissions()->syncWithoutDetaching(array_map(fn (Permission $permission): int => $permission->id, $permissions));

        $staff = User::factory()->create(['is_active' => true]);
        $staff->roles()->attach($role);
        return $staff;
    }
}
