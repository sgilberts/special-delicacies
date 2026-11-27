<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organisation;
use App\Models\PaymentGateway;
use App\Models\PaymentReceiptIntent;
use App\Models\PaymentTransaction;
use App\Services\CustomerCreditService;
use App\Services\PaymentAllocationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stage 4B — customer-initiated online deposits.
 *
 * Every test fakes the gateway HTTP layer: no live payment is ever made and
 * only trusted server-side verification may complete a receipt.
 */
class Stage4bCustomerOnlineDepositTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private PaymentGateway $gateway;
    private array $verifyCharge = ['status' => 'abandoned'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create([
            'name' => 'Online Deposit Customer', 'phone' => '0200000901', 'email' => 'online-deposit@example.test',
            'password' => 'customer-password', 'is_active' => true,
        ]);
        $this->gateway = PaymentGateway::create([
            'provider' => 'paystack', 'display_name' => 'Paystack Test', 'public_key' => 'pk_test',
            'secret_key' => 'sk_test_online_deposit', 'is_enabled' => true, 'is_default' => true,
        ]);
    }

    public function test_authenticated_customer_can_initiate_an_online_deposit(): void
    {
        $this->fakeGateway();

        $response = $this->initiate()->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.intent', 'deposit')
            ->assertJsonPath('data.checkout_url', 'https://checkout.paystack.com/test-session')
            ->assertJsonPath('data.payer.type', 'customer');

        $transaction = PaymentTransaction::sole();
        $this->assertSame($response->json('data.reference'), $transaction->transaction_reference);
        $this->assertNull($transaction->order_id);
        $this->assertSame('invoice', $transaction->payment_purpose);
        $this->assertSame($this->customer->id, (int) $transaction->customer_id);
        $this->assertNull($transaction->organisation_id);
        $this->assertSame('50.00', $transaction->amount);
        $this->assertSame('GHS', $transaction->currency);
        $this->assertSame('pending', $transaction->status);
        $this->assertSame('deposit', data_get($transaction->metadata, 'intent'));
        // A pending deposit is never a receipt yet.
        $this->assertSame(0, PaymentReceiptIntent::count());
    }

    public function test_unauthenticated_customer_cannot_initiate_or_read_deposits(): void
    {
        $this->postJson('/api/v1/customer/deposits', [])->assertUnauthorized();
        $this->getJson('/api/v1/customer/deposits')->assertUnauthorized();
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_customer_cannot_read_another_customers_deposit(): void
    {
        $this->fakeGateway();
        $reference = $this->initiate()->assertCreated()->json('data.reference');

        $other = Customer::create([
            'name' => 'Unrelated Deposit Customer', 'phone' => '0200000902', 'email' => 'unrelated-deposit@example.test',
            'password' => 'customer-password', 'is_active' => true,
        ]);
        $this->withToken($this->tokenFor($other))->getJson('/api/v1/customer/deposits/'.$reference)->assertNotFound();
        $this->withToken($this->tokenFor($other))->postJson('/api/v1/customer/deposits/'.$reference.'/verify')->assertNotFound();
    }

    public function test_organisation_payer_requires_financial_membership(): void
    {
        $this->fakeGateway();
        $organisation = Organisation::create([
            'reference' => 'ONLINE-DEP-ORG-1', 'owner_customer_id' => $this->customer->id,
            'name' => 'Online Deposit Organisation', 'status' => 'active',
        ]);

        $this->initiate(['payer_type' => 'organisation', 'organisation_id' => $organisation->id])->assertStatus(422);
        $this->assertSame(0, PaymentTransaction::count());

        $membership = $this->customer->organisationMemberships()->create([
            'organisation_id' => $organisation->id, 'role' => 'member', 'status' => 'active',
        ]);
        $membership->permissions()->create(['permission' => 'financials.view', 'granted_at' => now()]);

        $this->initiate(['payer_type' => 'organisation', 'organisation_id' => $organisation->id])->assertCreated()
            ->assertJsonPath('data.payer.type', 'organisation');
        $transaction = PaymentTransaction::sole();
        $this->assertNull($transaction->customer_id);
        $this->assertSame($organisation->id, (int) $transaction->organisation_id);

        $outsider = Customer::create([
            'name' => 'Organisation Outsider', 'phone' => '0200000903', 'email' => 'outsider@example.test',
            'password' => 'customer-password', 'is_active' => true,
        ]);
        $this->withToken($this->tokenFor($outsider))
            ->postJson('/api/v1/customer/deposits', [
                'payer_type' => 'organisation', 'organisation_id' => $organisation->id, 'intent' => 'deposit',
                'amount' => '10.00', 'payment_gateway_id' => $this->gateway->id, 'idempotency_key' => 'outsider-key-1',
            ])->assertStatus(422);
        $this->assertSame(1, PaymentTransaction::count());
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        $this->fakeGateway();
        foreach (['0', '0.00', '-5.00', 'abc', '1.234'] as $amount) {
            $this->initiate(['amount' => $amount])->assertStatus(422);
        }
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_inactive_unsupported_or_missing_gateway_is_rejected(): void
    {
        $this->fakeGateway();
        $this->gateway->forceFill(['is_enabled' => false])->save();
        $this->initiate()->assertStatus(422);

        $this->gateway->forceFill(['is_enabled' => true, 'secret_key' => null])->save();
        $this->initiate(['idempotency_key' => 'deposit-key-0002'])->assertStatus(422);

        $this->initiate(['idempotency_key' => 'deposit-key-0003', 'payment_gateway_id' => 999999])->assertStatus(422);
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_duplicate_initiation_requests_do_not_create_duplicate_receipts(): void
    {
        $this->fakeGateway();
        $first = $this->initiate()->assertCreated()->json('data.reference');
        $second = $this->initiate()->assertCreated()->json('data.reference');

        $this->assertSame($first, $second);
        $this->assertSame(1, PaymentTransaction::count());

        // Reusing the request key for a different amount is refused.
        $this->initiate(['amount' => '99.00'])->assertStatus(422);
        $this->assertSame(1, PaymentTransaction::count());
    }

    public function test_successful_server_verification_completes_and_classifies_the_receipt(): void
    {
        $this->fakeGateway();
        $reference = $this->initiate()->assertCreated()->json('data.reference');
        $transaction = PaymentTransaction::sole();
        $this->fakeGateway($this->verifiedCharge($transaction));

        $this->postJson('/api/v1/customer/deposits/'.$reference.'/verify')->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.intent', 'deposit');

        $transaction->refresh();
        $this->assertSame('succeeded', $transaction->status);
        $this->assertNotNull($transaction->verified_at);
        $this->assertNotNull($transaction->received_at);
        $this->assertSame('987654321', $transaction->provider_reference);

        $intent = PaymentReceiptIntent::sole();
        $this->assertSame('deposit', $intent->intent);
        $this->assertSame($transaction->id, (int) $intent->payment_transaction_id);
        $this->assertNull($intent->actor_user_id);
        $this->assertSame('customer:'.$this->customer->id, $intent->actor_reference_snapshot);

        // The completed receipt is the canonical, credit-eligible record.
        $eligible = app(CustomerCreditService::class)->eligibleReceipts();
        $this->assertCount(1, $eligible->items());
        $this->assertSame($reference, $eligible->items()[0]['reference']);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        $this->fakeGateway();
        $reference = $this->initiate()->assertCreated()->json('data.reference');
        $payload = json_encode(['event' => 'charge.success', 'data' => [
            'status' => 'success', 'currency' => 'GHS', 'amount' => 5000, 'reference' => $reference, 'id' => 123,
        ]]);

        $this->webhook($payload, str_repeat('a', 128))->assertStatus(401);
        $this->assertSame('pending', PaymentTransaction::sole()->status);
        $this->assertSame(0, PaymentReceiptIntent::count());
    }

    public function test_gateway_amount_currency_and_reference_mismatches_are_rejected(): void
    {
        $this->fakeGateway();
        $reference = $this->initiate()->assertCreated()->json('data.reference');
        $transaction = PaymentTransaction::sole();

        $cases = [
            $this->verifiedCharge($transaction, ['amount' => 5001]),
            $this->verifiedCharge($transaction, ['currency' => 'USD']),
            $this->verifiedCharge($transaction, ['reference' => 'DEP-SOMEONE-ELSE']),
        ];
        foreach ($cases as $charge) {
            $this->fakeGateway($charge);
            $this->postJson('/api/v1/customer/deposits/'.$reference.'/verify')->assertOk()
                ->assertJsonPath('data.status', 'pending');
            $this->assertSame('pending', $transaction->fresh()->status);
            $this->assertSame(0, PaymentReceiptIntent::count());
        }
    }

    public function test_browser_return_alone_cannot_mark_a_deposit_successful(): void
    {
        $this->fakeGateway();
        $reference = $this->initiate()->assertCreated()->json('data.reference');
        $transaction = PaymentTransaction::sole();
        // The customer returns from an abandoned checkout: the provider has not taken payment.
        $this->fakeGateway($this->verifiedCharge($transaction, ['status' => 'abandoned', 'amount' => 0]));

        $this->getJson('/api/v1/customer/deposits/'.$reference)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame(0, PaymentReceiptIntent::count());
    }

    public function test_valid_webhook_completes_the_receipt_once_even_when_replayed(): void
    {
        $this->fakeGateway();
        $reference = $this->initiate()->assertCreated()->json('data.reference');
        $payload = json_encode(['event' => 'charge.success', 'data' => [
            'status' => 'success', 'currency' => 'GHS', 'amount' => 5000, 'reference' => $reference, 'id' => 424242,
        ]]);
        $signature = hash_hmac('sha512', $payload, 'sk_test_online_deposit');

        $this->webhook($payload, $signature)->assertOk();
        $this->webhook($payload, $signature)->assertOk();

        $transaction = PaymentTransaction::sole();
        $this->assertSame('succeeded', $transaction->status);
        $this->assertSame('424242', $transaction->provider_reference);
        $this->assertSame(1, PaymentReceiptIntent::count());
        $this->assertSame(1, PaymentTransaction::count());
    }

    public function test_failed_payments_cannot_become_credit(): void
    {
        $this->fakeGateway();
        $reference = $this->initiate()->assertCreated()->json('data.reference');
        $transaction = PaymentTransaction::sole();
        $this->fakeGateway($this->verifiedCharge($transaction, ['status' => 'failed']));

        $this->postJson('/api/v1/customer/deposits/'.$reference.'/verify')->assertOk()
            ->assertJsonPath('data.status', 'failed');
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame(0, PaymentReceiptIntent::count());

        try {
            app(CustomerCreditService::class)->designateCredit(
                $transaction->id, '10.00', 'online-deposit-credit-1', 'Failed deposit', $this->staffActor()
            );
            $this->fail('A failed deposit must never become customer credit.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('verified', $exception->getMessage());
        }
    }

    public function test_cancelled_webhook_cannot_become_credit(): void
    {
        $this->fakeGateway();
        $reference = $this->initiate()->assertCreated()->json('data.reference');
        $payload = json_encode(['event' => 'charge.failed', 'data' => ['status' => 'failed', 'reference' => $reference]]);
        $signature = hash_hmac('sha512', $payload, 'sk_test_online_deposit');

        $this->webhook($payload, $signature)->assertOk();
        $transaction = PaymentTransaction::sole();
        $this->assertSame('failed', $transaction->status);
        $this->assertSame(0, PaymentReceiptIntent::count());
    }

    public function test_customer_status_retrieval_is_scoped_and_repeated_reads_do_not_mutate_records(): void
    {
        $this->fakeGateway();
        $reference = $this->initiate()->assertCreated()->json('data.reference');
        $transaction = PaymentTransaction::sole();
        // Provider still reports an unfinished charge.
        $this->fakeGateway($this->verifiedCharge($transaction, ['status' => 'ongoing', 'amount' => 0]));

        $this->getJson('/api/v1/customer/deposits')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $reference)
            ->assertJsonPath('data.0.status', 'pending');
        $this->getJson('/api/v1/customer/deposits/'.$reference)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/v1/customer/deposits/'.$reference)->assertOk()->assertJsonPath('data.status', 'pending');

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->verified_at);
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame(0, PaymentReceiptIntent::count());
    }

    public function test_customer_cannot_force_success_by_posting_client_supplied_status(): void
    {
        $this->fakeGateway();
        $this->withToken($this->tokenFor($this->customer))->postJson('/api/v1/customer/deposits', [
            'payer_type' => 'customer', 'intent' => 'deposit', 'amount' => '50.00',
            'payment_gateway_id' => $this->gateway->id, 'idempotency_key' => 'deposit-key-0009',
            'status' => 'succeeded', 'verified_at' => now()->toIso8601String(), 'currency' => 'USD', 'customer_id' => 999,
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $transaction = PaymentTransaction::sole();
        $this->assertSame('pending', $transaction->status);
        $this->assertSame('GHS', $transaction->currency);
        $this->assertSame($this->customer->id, (int) $transaction->customer_id);
    }

    public function test_verified_deposit_can_be_designated_as_credit_exactly_once(): void
    {
        $this->fakeGateway();
        $reference = $this->initiate()->assertCreated()->json('data.reference');
        $transaction = PaymentTransaction::sole();
        $this->fakeGateway($this->verifiedCharge($transaction));
        $this->postJson('/api/v1/customer/deposits/'.$reference.'/verify')->assertOk();

        $credit = app(CustomerCreditService::class);
        $actor = $this->staffActor();
        $entry = $credit->designateCredit($transaction->id, '50.00', 'online-deposit-credit-2', 'Customer deposit credit', $actor);
        $replay = $credit->designateCredit($transaction->id, '50.00', 'online-deposit-credit-2', 'Customer deposit credit', $actor);

        $this->assertSame($entry->id, $replay->id);
        $this->assertSame('50.00', PaymentAllocationService::formatMinorUnits($credit->balanceMinor($this->customer, 'GHS')));
        $this->assertSame(0, app(PaymentAllocationService::class)->availableMinorForPayment($transaction->id));
    }

    private function initiate(array $overrides = []): TestResponse
    {
        return $this->withToken($this->tokenFor($this->customer))->postJson('/api/v1/customer/deposits', array_merge([
            'payer_type' => 'customer',
            'intent' => 'deposit',
            'amount' => '50.00',
            'payment_gateway_id' => $this->gateway->id,
            'idempotency_key' => 'deposit-key-0001',
        ], $overrides));
    }

    private function tokenFor(Customer $customer): string
    {
        $this->app['auth']->forgetGuards();
        return $customer->createToken('customer-session')->plainTextToken;
    }

    private function staffActor(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['is_active' => true]);
    }

    /**
     * One mutable fake for the whole test: Laravel keeps previously registered
     * stubs, so the provider response is switched through this property rather
     * than by registering a second fake.
     */
    private function fakeGateway(?array $verifyCharge = null): void
    {
        if ($verifyCharge !== null) {
            $this->verifyCharge = $verifyCharge;
        }
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/transaction/initialize')) {
                return Http::response([
                    'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/test-session'],
                ]);
            }
            return Http::response(['status' => true, 'data' => $this->verifyCharge]);
        });
    }

    private function verifiedCharge(PaymentTransaction $transaction, array $overrides = []): array
    {
        return array_merge([
            'status' => 'success',
            'reference' => $transaction->transaction_reference,
            'currency' => 'GHS',
            'amount' => (int) round((float) $transaction->amount * 100),
            'id' => 987654321,
        ], $overrides);
    }

    private function webhook(string $payload, string $signature): TestResponse
    {
        return $this->call('POST', '/api/v1/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $payload);
    }
}
