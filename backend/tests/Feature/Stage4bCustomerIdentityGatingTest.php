<?php

namespace Tests\Feature;

use App\Contracts\SmsOtpDelivery;
use App\Mail\AccountOtpMail;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Stage 4B customer identity acceptance: email-or-phone sign-in and the
 * both-contact verification gate that protects the full customer feature set.
 */
class Stage4bCustomerIdentityGatingTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $phone = '0200000601', ?string $email = 'identity@example.test'): Customer
    {
        return Customer::create([
            'name' => 'Identity Customer', 'phone' => $phone, 'email' => $email,
            'password' => 'correct-horse-2026', 'is_active' => true,
        ]);
    }

    public function test_customer_can_sign_in_with_either_the_registered_email_or_telephone(): void
    {
        $customer = $this->customer();

        $this->postJson('/api/v1/customers/login', ['email' => '  IDENTITY@example.test ', 'password' => 'correct-horse-2026'])
            ->assertOk()->assertJsonPath('data.customer.id', $customer->id);
        $this->postJson('/api/v1/customers/login', ['phone' => '0200000601', 'password' => 'correct-horse-2026'])
            ->assertOk()->assertJsonPath('data.customer.id', $customer->id);
    }

    public function test_incorrect_or_missing_credentials_are_rejected_without_leaking_accounts(): void
    {
        $this->customer();

        $this->postJson('/api/v1/customers/login', ['email' => 'identity@example.test', 'password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonPath('success', false);
        $this->postJson('/api/v1/customers/login', ['phone' => '0200000601', 'password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonPath('success', false);
        $this->postJson('/api/v1/customers/login', ['email' => 'missing@example.test', 'password' => 'correct-horse-2026'])
            ->assertUnprocessable()->assertJsonPath('success', false);
        $this->postJson('/api/v1/customers/login', ['password' => 'correct-horse-2026'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->assertGuest('sanctum');
    }

    public function test_unverified_email_and_phone_keep_financial_features_restricted(): void
    {
        $this->assertFinancialFeaturesGated(false, false, ['email', 'phone']);
    }

    public function test_verified_email_alone_keeps_financial_features_restricted(): void
    {
        $this->assertFinancialFeaturesGated(true, false, ['phone']);
    }

    public function test_verified_phone_alone_keeps_financial_features_restricted(): void
    {
        $this->assertFinancialFeaturesGated(false, true, ['email']);
    }

    public function test_completing_the_second_verification_unlocks_features_on_the_existing_session(): void
    {
        Mail::fake();
        $sms = new class implements SmsOtpDelivery
        {
            public array $messages = [];

            public function send(string $phone, string $message): void
            {
                $this->messages[] = [$phone, $message];
            }
        };
        $this->app->instance(SmsOtpDelivery::class, $sms);

        $customer = $this->customer('0200000620', 'unlock@example.test');
        $token = $customer->createToken('customer-session')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/customer/deposits/options')->assertForbidden();

        $emailChallenge = $this->withToken($token)->postJson('/api/v1/customer/verification/email/request')
            ->assertAccepted()->json('data.challenge_id');
        $emailCode = null;
        Mail::assertSent(AccountOtpMail::class, function (AccountOtpMail $mail) use (&$emailCode): bool {
            $emailCode = $mail->code;
            return true;
        });
        $this->withToken($token)->postJson('/api/v1/customer/verification/email/verify', ['challenge_id' => $emailChallenge, 'code' => $emailCode])->assertOk();

        // One channel verified is still restricted.
        $this->withToken($token)->getJson('/api/v1/customer/deposits/options')->assertForbidden()
            ->assertJsonPath('missing', ['phone']);

        $phoneChallenge = $this->withToken($token)->postJson('/api/v1/customer/verification/phone/request')
            ->assertAccepted()->json('data.challenge_id');
        preg_match('/code is (\d{6})/', $sms->messages[0][1], $matches);
        $this->withToken($token)->postJson('/api/v1/customer/verification/phone/verify', ['challenge_id' => $phoneChallenge, 'code' => $matches[1]])->assertOk();

        $this->withToken($token)->getJson('/api/v1/customer/deposits/options')->assertOk()
            ->assertJsonStructure(['data' => ['payers', 'gateways', 'intents', 'currency']]);
        $this->withToken($token)->getJson('/api/v1/customer/credit')->assertOk();

        // Same account, same identity, same session token.
        $this->assertSame($customer->id, $customer->fresh()->id);
        $this->assertSame(1, $customer->tokens()->count());
    }

    public function test_guest_tokens_still_cannot_reach_customer_financial_endpoints(): void
    {
        $this->getJson('/api/v1/customer/deposits')->assertUnauthorized();
        $this->getJson('/api/v1/customer/credit')->assertUnauthorized();
    }

    /** @param string[] $missing */
    private function assertFinancialFeaturesGated(bool $emailVerified, bool $phoneVerified, array $missing): void
    {
        $customer = $this->customer();
        $customer->forceFill([
            'email_verified_at' => $emailVerified ? now() : null,
            'phone_verified_at' => $phoneVerified ? now() : null,
        ])->save();
        $token = $customer->createToken('gating')->plainTextToken;

        foreach (['/api/v1/customer/deposits', '/api/v1/customer/deposits/options', '/api/v1/customer/credit', '/api/v1/customer/invoices', '/api/v1/customer/quotations'] as $uri) {
            $this->withToken($token)->getJson($uri)->assertForbidden()
                ->assertJsonPath('code', 'customer_verification_required')
                ->assertJsonPath('missing', $missing)
                ->assertJsonMissingPath('data');
        }
        $this->withToken($token)->postJson('/api/v1/customer/deposits', [
            'payer_type' => 'customer', 'intent' => 'deposit', 'amount' => '10.00',
            'payment_gateway_id' => 1, 'idempotency_key' => 'gate-'.$customer->id,
        ])->assertForbidden()->assertJsonPath('code', 'customer_verification_required');

        // The account surfaces needed to finish verification stay open.
        $this->withToken($token)->getJson('/api/v1/customer/profile')->assertOk();
        $this->withToken($token)->getJson('/api/v1/customer/orders')->assertOk();
        $this->withToken($token)->patchJson('/api/v1/customer/profile', ['name' => 'Identity Customer'])->assertOk();
    }
}
