<?php

namespace Tests\Feature;

use App\Contracts\SmsOtpDelivery;
use App\Models\Customer;
use App\Models\CustomerCreditLedgerEntry;
use App\Models\PaymentReceiptIntent;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerCreditService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the Stage 4B completion pass: contact-verification
 * preservation, challenge isolation, receipt-reference idempotency, ledger
 * immutability and customer isolation of the financial endpoints.
 */
class Stage4bRemediationTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create([
            'name' => 'Remediation Customer', 'phone' => '0200000801', 'email' => 'remediation@example.test',
            'password' => 'old-customer-password', 'is_active' => true,
        ]);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_reformatted_phone_number_preserves_verification_but_a_new_number_clears_it(): void
    {
        $this->customer->forceFill(['phone_verified_at' => now()])->save();
        $token = $this->customer->createToken('customer-session')->plainTextToken;

        $this->withToken($token)->patchJson('/api/v1/customer/profile', ['phone' => '+233 20 000 0801'])
            ->assertOk();
        $this->assertNotNull($this->customer->fresh()->phone_verified_at, 'Reformatting the same number must not clear verification.');

        $this->withToken($token)->patchJson('/api/v1/customer/profile', ['phone' => '0200000802'])
            ->assertOk();
        $this->assertNull($this->customer->fresh()->phone_verified_at, 'A different number must require fresh verification.');

        $staff = User::factory()->create(['phone' => '0200000803', 'is_active' => true]);
        $staff->forceFill(['phone_verified_at' => now()])->save();
        Sanctum::actingAs($staff);
        $this->postJson('/api/v1/auth/profile', ['phone' => '+233 20 000 0803'])->assertOk();
        $this->assertNotNull($staff->fresh()->phone_verified_at);
        $this->postJson('/api/v1/auth/profile', ['phone' => '0200000804'])->assertOk();
        $this->assertNull($staff->fresh()->phone_verified_at);
    }

    public function test_verification_challenge_cannot_verify_another_customer_or_be_reused_for_password_reset(): void
    {
        $sms = new class implements SmsOtpDelivery {
            public array $messages = [];
            public function send(string $phone, string $message): void { $this->messages[] = [$phone, $message]; }
        };
        $this->app->instance(SmsOtpDelivery::class, $sms);

        $token = $this->customer->createToken('customer-session')->plainTextToken;
        $challengeId = $this->withToken($token)->postJson('/api/v1/customer/verification/phone/request')
            ->assertAccepted()->json('data.challenge_id');
        preg_match('/code is (\d{6})/', $sms->messages[0][1], $matches);
        $code = $matches[1];

        $other = Customer::create([
            'name' => 'Other Customer', 'phone' => '0200000805', 'email' => 'other-remediation@example.test',
            'password' => 'other-customer-password', 'is_active' => true,
        ]);
        $otherToken = $other->createToken('customer-session')->plainTextToken;
        // Each HTTP request must authenticate independently; the guard caches the
        // first resolved user for the lifetime of a single test case.
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->postJson('/api/v1/customer/verification/phone/verify', [
            'challenge_id' => $challengeId, 'code' => $code,
        ])->assertUnprocessable();
        $this->assertNull($other->fresh()->phone_verified_at);
        $this->assertNull($this->customer->fresh()->phone_verified_at);

        // A verification challenge must not authorise a password reset.
        $this->postJson('/api/v1/customers/password/phone/reset', [
            'challenge_id' => $challengeId, 'code' => $code,
            'password' => 'new-phone-password', 'password_confirmation' => 'new-phone-password',
        ])->assertUnprocessable();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('old-customer-password', $this->customer->fresh()->password));
    }

    public function test_receipt_intent_reference_cannot_be_reused_for_a_different_receipt(): void
    {
        $first = $this->receipt($this->customer, '10.00', 'REMEDIATION-PAY-1');
        $second = $this->receipt($this->customer, '10.00', 'REMEDIATION-PAY-2');
        $credit = app(CustomerCreditService::class);

        $credit->classifyReceipt($first->id, 'deposit', 'remediation-intent-1', 'First receipt deposit', $this->actor);
        try {
            $credit->classifyReceipt($second->id, 'deposit', 'remediation-intent-1', 'Second receipt deposit', $this->actor);
            $this->fail('A receipt-intent reference must be unique across receipts.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already used', $exception->getMessage());
        }
        $this->assertSame(1, PaymentReceiptIntent::query()->count());
    }

    public function test_unverified_receipts_and_unclassified_receipts_cannot_become_credit(): void
    {
        $pending = $this->receipt($this->customer, '10.00', 'REMEDIATION-PAY-3', [
            'status' => 'pending', 'verified_at' => null, 'received_at' => null,
        ]);
        $credit = app(CustomerCreditService::class);
        try {
            $credit->classifyReceipt($pending->id, 'deposit', 'remediation-intent-2', 'Unverified receipt', $this->actor);
            $this->fail('An unverified receipt must not be classified.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('verified', $exception->getMessage());
        }

        $verified = $this->receipt($this->customer, '10.00', 'REMEDIATION-PAY-4');
        try {
            $credit->designateCredit($verified->id, '5.00', 'remediation-credit-1', 'Skip classification', $this->actor);
            $this->fail('Credit designation requires an explicit receipt intent.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Classify the receipt', $exception->getMessage());
        }
        $this->assertSame(0, CustomerCreditLedgerEntry::query()->count());
    }

    public function test_credit_ledger_entries_are_immutable_through_the_model(): void
    {
        $payment = $this->receipt($this->customer, '25.00', 'REMEDIATION-PAY-5');
        $credit = app(CustomerCreditService::class);
        $credit->classifyReceipt($payment->id, 'deposit', 'remediation-intent-3', 'Deposit for ledger test', $this->actor);
        $entry = $credit->designateCredit($payment->id, '25.00', 'remediation-credit-2', 'Ledger immutability', $this->actor);

        try {
            $entry->forceFill(['reason' => 'Tampered history'])->save();
            $this->fail('Ledger entries must not be updated.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        try {
            $entry->delete();
            $this->fail('Ledger entries must not be deleted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }

        $stored = CustomerCreditLedgerEntry::query()->findOrFail($entry->id);
        $this->assertSame('Ledger immutability', $stored->reason);
        $this->assertSame('25.00', $stored->amount);
    }

    public function test_customers_cannot_reach_staff_deposit_and_credit_endpoints(): void
    {
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/admin/customer-credit/receipts')->assertForbidden();
        $this->getJson('/api/v1/admin/customer-credit/balances')->assertForbidden();
        $this->postJson('/api/v1/admin/customer-credit/designations', [])->assertForbidden();
        $this->postJson('/api/v1/admin/customer-credit/applications', [])->assertForbidden();
    }

    public function test_customer_credit_view_is_scoped_to_the_authenticated_customer(): void
    {
        $payment = $this->receipt($this->customer, '15.00', 'REMEDIATION-PAY-6');
        $credit = app(CustomerCreditService::class);
        $credit->classifyReceipt($payment->id, 'deposit', 'remediation-intent-4', 'Scoped credit', $this->actor);
        $credit->designateCredit($payment->id, '15.00', 'remediation-credit-3', 'Scoped credit', $this->actor);

        $other = Customer::create([
            'name' => 'Unrelated Customer', 'phone' => '0200000806', 'email' => 'unrelated@example.test',
            'password' => 'unrelated-password', 'is_active' => true,
        ]);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/customer/credit')->assertOk()
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.payer.id', $other->id)
            ->assertJsonPath('data.accounts.0.balances', []);

        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/customer/credit')->assertOk()
            ->assertJsonPath('data.accounts.0.balances.0.available_balance', '15.00');
    }

    public function test_deposit_classification_requires_the_granular_manage_permission(): void
    {
        $payment = $this->receipt($this->customer, '10.00', 'REMEDIATION-PAY-7');
        $role = Role::create(['name' => 'Deposit Viewer Only', 'slug' => 'deposit-viewer-only']);
        $permission = Permission::firstOrCreate(
            ['slug' => 'financials.deposits.view'],
            ['name' => 'View Deposit and Advance Receipts', 'description' => 'View eligible receipts'],
        );
        $role->permissions()->sync([$permission->id]);
        $staff = User::factory()->create(['is_active' => true]);
        $staff->roles()->attach($role);

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/admin/customer-credit/receipts')->assertOk();
        $this->postJson("/api/v1/admin/customer-credit/receipts/{$payment->id}/intent", [
            'intent' => 'deposit', 'operation_reference' => 'remediation-intent-5', 'reason' => 'Not allowed',
        ])->assertForbidden();
        $this->assertSame(0, PaymentReceiptIntent::query()->count());
    }

    private function receipt(Customer $payer, string $amount, string $reference, array $overrides = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'order_id' => null, 'customer_id' => $payer->id, 'organisation_id' => null, 'payment_purpose' => 'invoice',
            'transaction_reference' => $reference, 'payment_method' => 'cash', 'amount' => $amount, 'currency' => 'GHS',
            'status' => 'succeeded', 'verified_at' => now(), 'received_at' => now(), 'reporting_date' => now()->toDateString(),
        ], $overrides));
    }
}
