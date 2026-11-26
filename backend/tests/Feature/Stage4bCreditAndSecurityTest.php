<?php

namespace Tests\Feature;

use App\Contracts\SmsOtpDelivery;
use App\Mail\AccountOtpMail;
use App\Mail\PasswordRecoveryMail;
use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\CustomerCreditLedgerEntry;
use App\Models\Organisation;
use App\Models\PaymentReceiptIntent;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerCreditService;
use App\Services\PaymentAllocationService;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Stage4bCreditAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create([
            'name' => 'Stage Four B Customer', 'phone' => '0200000701', 'email' => 'stage4b@example.test',
            'password' => 'old-customer-password', 'is_active' => true,
        ]);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_credit_designation_and_application_are_immutable_and_feed_invoice_balance(): void
    {
        $invoice = $this->invoice($this->customer, '200.00', 'STAGE4B-INV-1');
        $payment = $this->receipt($this->customer, '100.00', 'STAGE4B-PAY-1');
        $credit = app(CustomerCreditService::class);

        $intent = $credit->classifyReceipt($payment->id, 'advance', 'stage4b-intent-0001', 'Advance received before invoice', $this->actor);
        $this->assertSame('advance', $intent->intent);
        $entry = $credit->designateCredit($payment->id, '60.00', 'stage4b-credit-0001', 'Customer requested account credit', $this->actor);
        $replay = $credit->designateCredit($payment->id, '60.00', 'stage4b-credit-0001', 'Customer requested account credit', $this->actor);
        $this->assertSame($entry->id, $replay->id);
        $this->assertSame(1, CustomerCreditLedgerEntry::where('entry_type', 'designation')->count());
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits(app(PaymentAllocationService::class)->availableMinorForPayment($payment->id)));

        $applied = $credit->applyCredit($invoice->id, '30.00', 'stage4b-apply-0001', 'Apply credit to issued invoice', $this->actor);
        $this->assertSame('30.00', PaymentAllocationService::formatMinorUnits($credit->balanceMinor($this->customer, 'GHS')));
        $this->assertSame($applied->id, $credit->applyCredit($invoice->id, '30.00', 'stage4b-apply-0001', 'Apply credit to issued invoice', $this->actor)->id);

        app(PaymentAllocationService::class)->allocate($payment->id, [['commercial_document_id' => $invoice->id, 'amount' => '40.00']], 'stage4b-payment-allocation-1', $this->actor);
        $summary = app(PaymentAllocationService::class)->balance($invoice);
        $this->assertSame('40.00', $summary['allocated_amount']);
        $this->assertSame('30.00', $summary['credit_applied_amount']);
        $this->assertSame('70.00', $summary['settled_amount']);
        $this->assertSame('130.00', $summary['outstanding_balance']);
        $this->assertSame(0, app(PaymentAllocationService::class)->availableMinorForPayment($payment->id));

        $this->expectException(DomainException::class);
        $credit->designateCredit($payment->id, '1.00', 'stage4b-credit-0002', 'Attempt to designate spent funds', $this->actor);
    }

    public function test_credit_application_rejects_other_payer_currency_and_overapplication(): void
    {
        $invoice = $this->invoice($this->customer, '25.00', 'STAGE4B-INV-2');
        $other = Customer::create(['name' => 'Different Payer', 'phone' => '0200000702', 'email' => 'other-stage4b@example.test', 'password' => 'old-customer-password', 'is_active' => true]);
        $otherInvoice = $this->invoice($other, '25.00', 'STAGE4B-INV-3');
        $currencyInvoice = $this->invoice($this->customer, '25.00', 'STAGE4B-INV-4', ['currency' => 'USD']);
        $payment = $this->receipt($this->customer, '10.00', 'STAGE4B-PAY-2');
        $credit = app(CustomerCreditService::class);
        $credit->classifyReceipt($payment->id, 'deposit', 'stage4b-intent-0002', 'Advance payment', $this->actor);
        $credit->designateCredit($payment->id, '10.00', 'stage4b-credit-0003', 'Explicit credit designation', $this->actor);

        try {
            $credit->applyCredit($otherInvoice->id, '1.00', 'stage4b-apply-0002', 'Wrong customer', $this->actor);
            $this->fail('A customer credit must not cross payer accounts.');
        } catch (DomainException $exception) { $this->assertStringContainsString('available credit', $exception->getMessage()); }
        try {
            $credit->applyCredit($currencyInvoice->id, '1.00', 'stage4b-apply-0003', 'Wrong currency', $this->actor);
            $this->fail('A GHS balance must not be applied to a USD invoice.');
        } catch (DomainException $exception) { $this->assertStringContainsString('available credit', $exception->getMessage()); }
        try {
            $credit->applyCredit($invoice->id, '26.00', 'stage4b-apply-0004', 'Invoice over-application', $this->actor);
            $this->fail('Credit cannot exceed invoice outstanding balance.');
        } catch (DomainException $exception) { $this->assertStringContainsString('available credit', $exception->getMessage()); }
        $this->assertSame(0, CustomerCreditLedgerEntry::where('entry_type', 'application')->count());
    }

    public function test_customer_credit_view_hides_organisation_credit_without_financial_membership_grant(): void
    {
        $organisation = Organisation::create(['reference' => 'STAGE4B-ORG-1', 'owner_customer_id' => $this->customer->id, 'name' => 'Stage4B Organisation', 'status' => 'active']);
        $orgPayment = $this->receipt($this->customer, '20.00', 'STAGE4B-PAY-ORG', ['customer_id' => null, 'organisation_id' => $organisation->id]);
        $credit = app(CustomerCreditService::class);
        $credit->classifyReceipt($orgPayment->id, 'deposit', 'stage4b-intent-org-1', 'Organisation deposit', $this->actor);
        $credit->designateCredit($orgPayment->id, '20.00', 'stage4b-credit-org-1', 'Organisation credit', $this->actor);
        $this->customer->createToken('customer-session');
        Sanctum::actingAs($this->customer);

        $this->getJson('/api/v1/customer/credit')->assertOk()->assertJsonCount(1, 'data.accounts');
        $membership = $this->customer->organisationMemberships()->create([
            'organisation_id' => $organisation->id, 'role' => 'owner', 'status' => 'active',
        ]);
        $membership->permissions()->create(['permission' => 'financials.view', 'granted_at' => now()]);
        $this->getJson('/api/v1/customer/credit')->assertOk()->assertJsonCount(2, 'data.accounts')
            ->assertJsonPath('data.accounts.1.balances.0.available_balance', '20.00');
    }

    public function test_stage4b_permissions_are_granular_and_not_granted_to_ordinary_admin(): void
    {
        app(RoleAndPermissionSeeder::class)->run();
        $adminRole = Role::where('slug', 'admin')->firstOrFail();
        $this->assertFalse($adminRole->permissions()->whereIn('slug', [
            'financials.deposits.view', 'financials.deposits.manage', 'financials.customer_credit.view', 'financials.customer_credit.manage',
        ])->exists());
        $superAdmin = Role::where('slug', 'super-admin')->firstOrFail();
        $this->assertTrue($superAdmin->permissions()->where('slug', 'financials.customer_credit.manage')->exists());

        $role = Role::create(['name' => 'Deposit Viewer', 'slug' => 'deposit-viewer']);
        $permission = Permission::firstOrCreate(['slug' => 'financials.deposits.view'], ['name' => 'View deposit receipts', 'description' => 'View verified unallocated receipts']);
        $role->permissions()->sync([$permission->id]);
        $staff = User::factory()->create(['is_active' => true]);
        $staff->roles()->attach($role);
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/admin/customer-credit/receipts')->assertOk();
        $this->getJson('/api/v1/admin/customer-credit/balances')->assertForbidden();
        $this->postJson('/api/v1/admin/customer-credit/applications', [])->assertForbidden();
    }

    public function test_phone_otp_is_hashed_one_time_rate_limited_and_not_returned_by_api(): void
    {
        $sms = new class implements SmsOtpDelivery {
            public array $messages = [];
            public function send(string $phone, string $message): void { $this->messages[] = [$phone, $message]; }
        };
        $this->app->instance(SmsOtpDelivery::class, $sms);
        $token = $this->customer->createToken('customer-session')->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/v1/customer/verification/phone/request');
        $response->assertAccepted()->assertJsonMissingPath('data.code');
        $challengeId = $response->json('data.challenge_id');
        $this->assertSame('+233200000701', $sms->messages[0][0]);
        preg_match('/code is (\d{6})/', $sms->messages[0][1], $matches);
        $code = $matches[1];
        $challenge = \App\Models\AccountSecurityChallenge::where('public_id', $challengeId)->firstOrFail();
        $this->assertNotSame($code, $challenge->secret_hash);
        $this->assertTrue(Hash::check($code, $challenge->secret_hash));

        $wrong = $code === '000000' ? '999999' : '000000';
        $this->withToken($token)->postJson('/api/v1/customer/verification/phone/verify', ['challenge_id' => $challengeId, 'code' => $wrong])->assertUnprocessable();
        $this->assertSame(1, (int) $challenge->fresh()->failed_attempts);
        $this->withToken($token)->postJson('/api/v1/customer/verification/phone/verify', ['challenge_id' => $challengeId, 'code' => $code])->assertOk();
        $this->assertNotNull($this->customer->fresh()->phone_verified_at);
        $this->withToken($token)->postJson('/api/v1/customer/verification/phone/verify', ['challenge_id' => $challengeId, 'code' => $code])->assertUnprocessable();
    }

    public function test_email_otp_and_email_reset_link_are_bound_to_account_and_single_use(): void
    {
        Mail::fake();
        $token = $this->customer->createToken('customer-session')->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/v1/customer/verification/email/request');
        $response->assertAccepted()->assertJsonMissingPath('data.code');
        $challengeId = $response->json('data.challenge_id');
        $code = null;
        Mail::assertSent(AccountOtpMail::class, function (AccountOtpMail $mail) use (&$code): bool { $code = $mail->code; return true; });
        $this->withToken($token)->postJson('/api/v1/customer/verification/email/verify', ['challenge_id' => $challengeId, 'code' => $code])->assertOk();
        $this->assertNotNull($this->customer->fresh()->email_verified_at);

        $known = $this->postJson('/api/v1/customers/password/forgot', ['email' => $this->customer->email]);
        $unknown = $this->postJson('/api/v1/customers/password/forgot', ['email' => 'missing@example.test']);
        $known->assertAccepted(); $unknown->assertAccepted();
        $this->assertSame($known->json(), $unknown->json());

        $resetUrl = null;
        Mail::assertSent(PasswordRecoveryMail::class, function (PasswordRecoveryMail $mail) use (&$resetUrl): bool { $resetUrl = $mail->resetUrl; return true; });
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);
        $reset = $this->postJson('/api/v1/customers/password/reset', [
            'challenge_id' => $query['challenge'], 'token' => $query['token'],
            'password' => 'new-customer-password', 'password_confirmation' => 'new-customer-password',
        ]);
        $reset->assertOk()->assertJsonMissingPath('data.token');
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_type' => Customer::class, 'tokenable_id' => $this->customer->id]);
        $this->postJson('/api/v1/customers/password/reset', [
            'challenge_id' => $query['challenge'], 'token' => $query['token'],
            'password' => 'another-customer-password', 'password_confirmation' => 'another-customer-password',
        ])->assertUnprocessable();
    }

    public function test_phone_recovery_uses_sms_reference_and_invalidates_customer_sessions(): void
    {
        $sms = new class implements SmsOtpDelivery {
            public array $messages = [];
            public function send(string $phone, string $message): void { $this->messages[] = [$phone, $message]; }
        };
        $this->app->instance(SmsOtpDelivery::class, $sms);
        $this->customer->createToken('customer-session');
        $response = $this->postJson('/api/v1/customers/password/phone/forgot', ['phone' => '+233 20 000 0701']);
        $response->assertAccepted()->assertJsonMissingPath('data.challenge_id')->assertJsonMissingPath('data.code');
        preg_match('/code is (\d{6})/', $sms->messages[0][1], $codeMatch);
        preg_match('/Request reference: ([a-f0-9]{48})/', $sms->messages[0][1], $referenceMatch);
        $this->postJson('/api/v1/customers/password/phone/reset', [
            'challenge_id' => $referenceMatch[1], 'code' => $codeMatch[1],
            'password' => 'new-phone-password', 'password_confirmation' => 'new-phone-password',
        ])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_type' => Customer::class, 'tokenable_id' => $this->customer->id]);
        $this->postJson('/api/v1/customers/login', ['phone' => '0200000701', 'password' => 'new-phone-password'])->assertOk();
    }

    public function test_phone_recovery_refuses_ambiguous_normalized_phone_numbers(): void
    {
        $sms = new class implements SmsOtpDelivery {
            public array $messages = [];
            public function send(string $phone, string $message): void { $this->messages[] = [$phone, $message]; }
        };
        $this->app->instance(SmsOtpDelivery::class, $sms);
        Customer::create([
            'name' => 'Duplicate Phone Customer', 'phone' => '020-000-0701', 'email' => 'duplicate-phone@example.test',
            'password' => 'another-customer-password', 'is_active' => true,
        ]);

        $this->postJson('/api/v1/customers/password/phone/forgot', ['phone' => '+233 20 000 0701'])->assertAccepted();

        $this->assertSame([], $sms->messages);
        $this->assertDatabaseCount('account_security_challenges', 0);
    }

    public function test_phone_otp_attempt_limit_persists_and_expiry_prevents_replay(): void
    {
        $sms = new class implements SmsOtpDelivery {
            public array $messages = [];
            public function send(string $phone, string $message): void { $this->messages[] = [$phone, $message]; }
        };
        $this->app->instance(SmsOtpDelivery::class, $sms);
        $token = $this->customer->createToken('customer-session')->plainTextToken;
        $id = $this->withToken($token)->postJson('/api/v1/customer/verification/phone/request')->assertAccepted()->json('data.challenge_id');
        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->postJson('/api/v1/customer/verification/phone/verify', ['challenge_id' => $id, 'code' => '999999'])->assertUnprocessable();
        }
        $challenge = \App\Models\AccountSecurityChallenge::where('public_id', $id)->firstOrFail();
        $this->assertSame(5, (int) $challenge->failed_attempts);
        $this->assertNotNull($challenge->consumed_at);
        $this->assertNull($this->customer->fresh()->phone_verified_at);
    }

    public function test_otp_resend_cooldown_and_expiry_are_enforced(): void
    {
        $sms = new class implements SmsOtpDelivery {
            public array $messages = [];
            public function send(string $phone, string $message): void { $this->messages[] = [$phone, $message]; }
        };
        $this->app->instance(SmsOtpDelivery::class, $sms);
        $token = $this->customer->createToken('customer-session')->plainTextToken;
        $request = $this->withToken($token)->postJson('/api/v1/customer/verification/phone/request');
        $request->assertAccepted();
        $this->withToken($token)->postJson('/api/v1/customer/verification/phone/request')->assertStatus(429);
        $challengeId = $request->json('data.challenge_id');
        preg_match('/code is (\d{6})/', $sms->messages[0][1], $matches);
        $this->travel(11)->minutes();
        $this->withToken($token)->postJson('/api/v1/customer/verification/phone/verify', ['challenge_id' => $challengeId, 'code' => $matches[1]])->assertUnprocessable();
        $this->assertNull($this->customer->fresh()->phone_verified_at);
    }

    public function test_staff_and_customer_email_reset_links_cannot_cross_account_types(): void
    {
        Mail::fake();
        $staff = User::factory()->create(['email' => 'stage4b-staff@example.test', 'password' => 'old-staff-password', 'is_active' => true]);
        $staff->createToken('staff-session');
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $staff->email])->assertAccepted();
        $resetUrl = null;
        Mail::assertSent(PasswordRecoveryMail::class, function (PasswordRecoveryMail $mail) use (&$resetUrl): bool {
            if (str_contains($mail->resetUrl, '/admin/password/reset')) $resetUrl = $mail->resetUrl;
            return true;
        });
        parse_str((string) parse_url((string) $resetUrl, PHP_URL_QUERY), $query);
        $payload = [
            'challenge_id' => $query['challenge'], 'token' => $query['token'],
            'password' => 'new-staff-password', 'password_confirmation' => 'new-staff-password',
        ];
        $this->postJson('/api/v1/customers/password/reset', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-staff-password', $staff->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_type' => User::class, 'tokenable_id' => $staff->id]);
    }

    private function invoice(Customer $payer, string $total, string $number, array $overrides = []): CommercialDocument
    {
        return CommercialDocument::create(array_merge([
            'document_number' => $number, 'document_type' => 'invoice', 'status' => 'issued',
            'customer_id' => $payer->id, 'currency' => 'GHS', 'subtotal' => $total,
            'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => $total,
            'issue_date' => today(), 'issued_at' => now(),
        ], $overrides));
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
