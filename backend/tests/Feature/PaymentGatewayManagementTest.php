<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\PaymentGateway;
use App\Models\Role;
use App\Models\User;
use App\Services\GatewayPaymentService;
use App\Services\PaymentTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentGatewayManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
        $this->superAdmin = User::create([
            'name' => 'Primary Admin', 'email' => 'primary@test.local',
            'password' => bcrypt('secret1234'), 'is_active' => true,
        ]);
        $this->superAdmin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());
    }

    public function test_restaurant_settings_and_gateway_configuration_endpoints_load(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/settings')->assertOk()->assertJsonPath('success', true);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/payment-gateways')
            ->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.supported_providers.0.provider', 'paystack');
    }

    public function test_admin_can_create_multiple_gateways_and_select_one_default_without_exposing_secrets(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')->postJson('/api/v1/admin/payment-gateways', [
            'provider' => 'paystack', 'display_name' => 'Paystack Test',
            'public_key' => 'pk_test_public', 'secret_key' => 'sk_test_private', 'webhook_secret' => 'whsec_test_private',
            'is_enabled' => true, 'is_default' => true,
        ])->assertCreated()->assertJsonMissingPath('data.secret_key')->assertJsonMissingPath('data.webhook_secret')
            ->assertJsonPath('data.webhook_secret_configured', true)->assertJsonPath('data.is_default', true);

        $paystack = PaymentGateway::where('provider', 'paystack')->firstOrFail();
        $this->assertNotSame('sk_test_private', $paystack->getRawOriginal('secret_key'));
        $this->assertNotSame('whsec_test_private', $paystack->getRawOriginal('webhook_secret'));

        $this->actingAs($this->superAdmin, 'sanctum')->postJson('/api/v1/admin/payment-gateways', [
            'provider' => 'hubtel', 'display_name' => 'Hubtel Collections',
            'public_key' => 'hubtel-api-id', 'secret_key' => 'hubtel-api-key',
            'configuration' => ['collection_account_number' => '11684'],
            'is_enabled' => true, 'is_default' => true,
        ])->assertCreated()->assertJsonPath('data.provider', 'hubtel')->assertJsonPath('data.is_default', true);

        $this->assertFalse($paystack->fresh()->is_default);
        $this->getJson('/api/v1/payment-options')
            ->assertOk()->assertJsonPath('data.online_payment_enabled', true)
            ->assertJsonPath('data.gateway_provider', 'hubtel')
            ->assertJsonPath('data.gateway_name', 'Hubtel Collections');

        $this->actingAs($this->superAdmin, 'sanctum')->deleteJson('/api/v1/admin/payment-gateways/'.$paystack->id)
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseMissing('payment_gateways', ['id' => $paystack->id]);
    }

    public function test_default_kora_gateway_starts_and_verifies_hosted_checkout(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_ends_with($request->url(), '/charges/initialize')) {
                return Http::response(['status' => true, 'data' => ['checkout_url' => 'https://checkout.korapay.com/test-session']]);
            }
            return Http::response(['status' => true, 'data' => [
                'reference' => rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH))),
                'status' => 'success', 'currency' => 'GHS', 'amount' => '25.50',
            ]]);
        });

        $gateway = PaymentGateway::create([
            'provider' => 'korapay', 'display_name' => 'Kora Ghana', 'secret_key' => 'sk_test_kora',
            'is_enabled' => true, 'is_default' => true,
        ]);
        $order = Order::create([
            'order_number' => 'ORD-KORA-TEST', 'order_type' => Order::TYPE_DELIVERY,
            'customer_name' => 'Test Customer', 'customer_phone' => '+233200000001',
            'customer_email' => 'customer@example.test', 'delivery_address' => 'Accra',
            'status' => Order::STATUS_PENDING, 'subtotal' => '25.50', 'delivery_fee' => '0.00',
            'total' => '25.50', 'payment_method' => Order::PAYMENT_METHOD_GATEWAY,
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
        ]);

        $payments = app(GatewayPaymentService::class);
        $this->assertSame('https://checkout.korapay.com/test-session', $payments->initialize($order));
        $order->refresh();
        $this->assertSame($gateway->id, $order->payment_gateway_id);
        $reference = $order->payment_reference;
        $this->assertNotEmpty($reference);

        $verified = $payments->verify($reference);
        $this->assertSame(Order::PAYMENT_STATUS_PAID, $verified?->payment_status);
    }

    public function test_existing_verified_gateway_success_repairs_pending_order_and_oversight_without_changing_fulfillment(): void
    {
        $gateway = PaymentGateway::create([
            'provider' => 'paystack', 'display_name' => 'Paystack', 'public_key' => 'pk_test',
            'secret_key' => 'sk_test', 'is_enabled' => true, 'is_default' => true,
        ]);
        $order = Order::create([
            'order_number' => 'ORD-REPAIR-PAID', 'order_type' => Order::TYPE_DELIVERY,
            'customer_name' => 'Test Customer', 'customer_phone' => '+233200000001',
            'customer_email' => 'repair@example.test', 'delivery_address' => 'Accra',
            'status' => Order::STATUS_PENDING, 'subtotal' => '25.50', 'delivery_fee' => '0.00',
            'total' => '25.50', 'payment_method' => Order::PAYMENT_METHOD_GATEWAY,
            'payment_status' => Order::PAYMENT_STATUS_PENDING, 'payment_reference' => 'SD-REPAIR-PAID',
            'payment_gateway_id' => $gateway->id,
        ]);
        $verifiedAt = now()->subMinute();
        $transaction = PaymentTransaction::create([
            'order_id' => $order->id, 'transaction_reference' => $order->payment_reference,
            'provider' => 'paystack', 'payment_method' => 'gateway', 'amount' => '25.50', 'currency' => 'GHS',
            'status' => 'succeeded', 'provider_reference' => 'gateway-charge-123', 'verified_at' => $verifiedAt,
            'received_at' => $verifiedAt, 'reporting_date' => $verifiedAt->toDateString(),
        ]);

        app(PaymentTransactionService::class)->recordGatewaySuccess($order, 'gateway-charge-123');

        $this->assertSame(Order::PAYMENT_STATUS_PAID, $order->fresh()->payment_status);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->count());
        $this->assertSame('succeeded', $transaction->fresh()->status);
        $this->actingAs($this->superAdmin, 'sanctum')->getJson('/api/v1/admin/orders/'.$order->id)
            ->assertOk()->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_details.transaction_status', 'succeeded')
            ->assertJsonPath('data.payment_details.currency', 'GHS');

        app(PaymentTransactionService::class)->recordGatewayStatus($order->fresh(), 'failed', 'late-failure');
        $this->assertSame(Order::PAYMENT_STATUS_PAID, $order->fresh()->payment_status);
        $this->assertSame('succeeded', $transaction->fresh()->status);
    }

    public function test_admin_favicon_upload_is_saved_and_returned_to_the_public_content_api(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->superAdmin, 'sanctum')->post('/api/v1/admin/content/favicon', [
            'favicon' => UploadedFile::fake()->image('restaurant-favicon.png', 32, 32),
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $url = $response->json('data.favicon_url');
        $path = basename(parse_url($url, PHP_URL_PATH));
        Storage::disk('public')->assertExists('branding/'.$path);
        $this->assertDatabaseHas('restaurant_settings', ['key' => 'favicon_url', 'value' => $url]);
        $this->getJson('/api/v1/content')->assertOk()->assertJsonPath('data.favicon_url', $url);
    }

    public function test_public_order_tracking_and_admin_order_list_include_gateway_payment_details(): void
    {
        $gateway = PaymentGateway::create([
            'provider' => 'paystack', 'display_name' => 'Paystack Live',
            'public_key' => 'pk_test', 'secret_key' => 'sk_test', 'is_enabled' => true, 'is_default' => true,
        ]);
        $order = Order::create([
            'order_number' => 'SD-TRACK-7712', 'order_type' => Order::TYPE_DELIVERY,
            'customer_name' => 'Tracking Customer', 'customer_phone' => '+233200000001',
            'customer_email' => 'track@example.test', 'delivery_address' => 'Accra',
            'status' => Order::STATUS_CONFIRMED, 'subtotal' => '25.50', 'delivery_fee' => '0.00',
            'total' => '25.50', 'payment_method' => Order::PAYMENT_METHOD_GATEWAY,
            'payment_status' => Order::PAYMENT_STATUS_PAID, 'payment_reference' => 'SDREF-7712',
            'payment_gateway_id' => $gateway->id,
        ]);

        $this->getJson('/api/v1/orders/track/'.$order->order_number)
            ->assertOk()->assertJsonPath('data.payment_details.status', 'paid')
            ->assertJsonPath('data.payment_details.gateway_name', 'Paystack Live')
            ->assertJsonPath('data.payment_details.reference', 'SDREF-7712');

        $this->actingAs($this->superAdmin, 'sanctum')->getJson('/api/v1/admin/orders')
            ->assertOk()->assertJsonPath('data.0.payment_details.gateway_provider', 'paystack')
            ->assertJsonPath('data.0.payment_details.amount', '25.50');
    }

    public function test_paystack_modern_webhook_signature_verifies_raw_payload_and_rejects_stale_signatures(): void
    {
        [$gateway, $order] = $this->makeGatewayOrder('paystack', 'whsec_kp_test');
        $payload = json_encode(['event' => 'charge.success', 'data' => [
            'reference' => $order->payment_reference, 'status' => 'success', 'currency' => 'GHS', 'amount' => 2550,
        ]], JSON_UNESCAPED_SLASHES);
        $this->assertIsString($payload);
        $service = app(GatewayPaymentService::class);

        $staleTimestamp = time() - 301;
        $staleSignature = hash_hmac('sha512', $staleTimestamp.'.'.$payload, $gateway->webhook_secret);
        $this->assertFalse($service->consumeWebhook('paystack', $payload, '', "t={$staleTimestamp},v1=whk_test:{$staleSignature}"));
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->fresh()->payment_status);

        $timestamp = time();
        $signature = hash_hmac('sha512', $timestamp.'.'.$payload, $gateway->webhook_secret);
        $header = "t={$timestamp},v1=whk_test:{$signature}";
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYSTACK_SIGNATURE' => $header];
        $this->call('POST', '/api/v1/payments/paystack/webhook', [], [], [], $server, $payload)
            ->assertOk()->assertJsonPath('success', true);
        $this->call('POST', '/api/v1/payments/paystack/webhook', [], [], [], $server, $payload)
            ->assertOk()->assertJsonPath('success', true);
        $this->assertSame(Order::PAYMENT_STATUS_PAID, $order->fresh()->payment_status);
        $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->where('status', 'succeeded')->count());
    }

    public function test_kora_webhook_signature_uses_documented_data_json_and_reconciles_idempotently(): void
    {
        [$gateway, $order] = $this->makeGatewayOrder('korapay');
        $data = [
            'reference' => $order->payment_reference,
            'status' => 'success',
            'currency' => 'GHS',
            'amount' => 25.5,
            'description' => 'Dinner / pickup',
            'customer_name' => 'Renée',
        ];
        $payload = json_encode(['event' => ['type' => 'charge.success'], 'data' => $data]);
        $this->assertIsString($payload);
        $signature = hash_hmac('sha256', json_encode($data), $gateway->secret_key);
        $service = app(GatewayPaymentService::class);

        $this->assertFalse($service->consumeWebhook('korapay', $payload, str_repeat('0', 64)));
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->fresh()->payment_status);
        $this->assertTrue($service->consumeWebhook('korapay', $payload, $signature));
        $this->assertTrue($service->consumeWebhook('korapay', $payload, $signature));
        $this->assertSame(Order::PAYMENT_STATUS_PAID, $order->fresh()->payment_status);
        $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->where('status', 'succeeded')->count());
    }

    public function test_all_gateway_status_apis_mark_only_matching_successful_transactions_paid(): void
    {
        $cases = [];
        foreach (['paystack', 'hubtel', 'korapay'] as $provider) {
            [$gateway, $order] = $this->makeGatewayOrder($provider, null, '-OK');
            $cases[$order->payment_reference] = ['provider' => $provider, 'order' => $order, 'currency' => 'GHS', 'amount_ok' => true];
        }
        $this->fakeGatewayVerificationResponses($cases);

        foreach (['paystack', 'hubtel', 'korapay'] as $provider) {
            $order = collect($cases)->firstWhere('provider', $provider)['order'];
            $verified = app(GatewayPaymentService::class)->verify($order->payment_reference);

            $this->assertSame(Order::PAYMENT_STATUS_PAID, $verified?->payment_status, "{$provider} should reconcile an authoritative matching success.");
            $this->assertSame('succeeded', PaymentTransaction::where('order_id', $order->id)->value('status'));
        }
    }

    public function test_gateway_redirect_success_does_not_mark_wrong_amount_or_currency_paid(): void
    {
        $cases = [];
        foreach (['paystack', 'hubtel', 'korapay'] as $provider) {
            foreach ([['currency' => 'GHS', 'amount_ok' => false], ['currency' => 'USD', 'amount_ok' => true]] as $index => $case) {
                [, $order] = $this->makeGatewayOrder($provider, null, '-BAD-'.$index);
                $cases[$order->payment_reference] = ['provider' => $provider, 'order' => $order,
                    'currency' => $case['currency'], 'amount_ok' => $case['amount_ok']];
            }
        }
        $this->fakeGatewayVerificationResponses($cases);

        foreach ($cases as $case) {
            /** @var Order $order */
            $order = $case['order'];
            $provider = $case['provider'];
                $verified = app(GatewayPaymentService::class)->verify($order->payment_reference);

                $this->assertSame(Order::PAYMENT_STATUS_PENDING, $verified?->payment_status, "{$provider} mismatched verification must not mark an order paid.");
                $this->assertSame(0, PaymentTransaction::where('order_id', $order->id)->where('status', 'succeeded')->count());
        }
    }

    private function fakeGatewayVerificationResponses(array $cases): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($cases) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $reference = match ($host) {
                'api.paystack.co' => rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH))),
                'api-txnstatus.hubtel.com' => (string) ($query['clientReference'] ?? ''),
                'api.korapay.com' => rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH))),
                default => '',
            };
            $case = $cases[$reference] ?? null;
            if (! $case) return Http::response(['status' => false], 404);

            return Http::response($this->verificationBody(
                $case['provider'], $case['order'], $case['currency'], $case['amount_ok']
            ));
        });
    }

    private function verificationBody(string $provider, Order $order, string $currency, bool $amountMatches): array
    {
        $amount = $amountMatches ? (float) $order->total : (float) $order->total + 3;
        return match ($provider) {
            'paystack' => ['status' => true, 'data' => [
                'status' => 'success', 'reference' => $order->payment_reference,
                'currency' => $currency, 'amount' => (int) round($amount * 100),
            ]],
            'hubtel' => ['responseCode' => '0000', 'message' => 'Successful', 'data' => [
                'status' => 'Paid', 'clientReference' => $order->payment_reference,
                'currencyCode' => $currency, 'amount' => $amount,
            ]],
            'korapay' => ['status' => true, 'data' => [
                'status' => 'success', 'reference' => $order->payment_reference,
                'currency' => $currency, 'amount' => $amount,
            ]],
        };
    }

    /** @return array{PaymentGateway, Order} */
    private function makeGatewayOrder(string $provider, ?string $webhookSecret = null, string $suffix = ''): array
    {
        $gateway = PaymentGateway::create([
            'provider' => $provider,
            'display_name' => ucfirst($provider).' Test',
            'public_key' => in_array($provider, ['paystack', 'hubtel'], true) ? 'pk_test' : null,
            'secret_key' => 'sk_test_'.$provider,
            'webhook_secret' => $webhookSecret,
            'configuration' => $provider === 'hubtel' ? ['collection_account_number' => '11684'] : null,
            'is_enabled' => true,
            'is_default' => true,
        ]);
        $order = Order::create([
            'order_number' => 'ORD-WEBHOOK-'.strtoupper($provider).$suffix,
            'order_type' => Order::TYPE_DELIVERY,
            'customer_name' => 'Webhook Customer',
            'customer_phone' => '+233200000001',
            'customer_email' => 'webhook@example.test',
            'delivery_address' => 'Accra',
            'status' => Order::STATUS_PENDING,
            'subtotal' => '25.50',
            'delivery_fee' => '0.00',
            'total' => '25.50',
            'payment_method' => Order::PAYMENT_METHOD_GATEWAY,
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'payment_reference' => 'SD-WEBHOOK-'.strtoupper($provider).$suffix,
            'payment_gateway_id' => $gateway->id,
        ]);

        return [$gateway, $order];
    }
}
