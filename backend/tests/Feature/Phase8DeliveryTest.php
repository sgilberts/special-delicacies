<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\MenuItem;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8 — Home Delivery System Feature Tests
 *
 * Covers: delivery zone management, delivery order creation, price integrity,
 * idempotency, delivery staff workflow, real-time events, and regression checks.
 */
class Phase8DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $deliveryStaff;
    private User $kitchenStaff;
    private User $waiter;
    private DeliveryZone $activeZone;
    private DeliveryZone $inactiveZone;
    private MenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);

        // Create users
        $this->admin = $this->createUserWithRole('super-admin');
        $this->deliveryStaff = $this->createUserWithRole('delivery-staff');
        $this->kitchenStaff = $this->createUserWithRole('kitchen-staff');
        $this->waiter = $this->createUserWithRole('waiter-waitress');

        // Create delivery zones
        $this->activeZone = DeliveryZone::create([
            'name'         => 'Accra Central',
            'description'  => 'Central Accra business district',
            'delivery_fee' => '15.00',
            'is_active'    => true,
        ]);

        $this->inactiveZone = DeliveryZone::create([
            'name'         => 'Inactive Zone',
            'description'  => null,
            'delivery_fee' => '25.00',
            'is_active'    => false,
        ]);

        // Create menu category and item
        $category = MenuCategory::create([
            'name'        => 'Main Dishes',
            'slug'        => 'main-dishes',
            'description' => null,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);

        $this->menuItem = MenuItem::create([
            'category_id'  => $category->id,
            'name'         => 'Jollof Rice',
            'slug'         => 'jollof-rice',
            'description'  => 'Classic West African rice dish',
            'price'        => '35.00',
            'is_available' => true,
            'sort_order'   => 1,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createUserWithRole(string $roleSlug, ?string $email = null): User
    {
        $user = User::create([
            'name'      => ucfirst($roleSlug) . ' User',
            'email'     => $email ?? ($roleSlug . '@test.com'),
            'password'  => bcrypt('secret'),
            'is_active' => true,
        ]);

        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }

    private function deliveryPayload(array $overrides = []): array
    {
        return array_merge([
            'order_type'      => 'delivery',
            'customer_name'   => 'Kwame Asante',
            'customer_phone'  => '0241234567',
            'delivery_zone_id'=> $this->activeZone->id,
            'delivery_address'=> '15 Independence Ave, Accra',
            'customer_notes'  => 'Please ring the bell twice',
            'items'           => [
                [
                    'menu_item_id'        => $this->menuItem->id,
                    'quantity'            => 2,
                    'special_instructions'=> null,
                ],
            ],
        ], $overrides);
    }

    private function configureTestGateway(): void
    {
        PaymentGateway::create([
            'provider' => 'paystack', 'display_name' => 'Paystack',
            'public_key' => 'pk_test_public', 'secret_key' => 'sk_test_secret',
            'is_enabled' => true, 'is_default' => true,
        ]);
        Http::fake(['https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/test-session'],
        ], 200)]);
    }

    // ─── 1. Public Delivery Zones API ─────────────────────────────────────────

    public function test_public_can_list_active_delivery_zones(): void
    {
        $response = $this->getJson('/api/v1/delivery-zones');

        $response->assertOk()
            ->assertJsonStructure(['success', 'data'])
            ->assertJsonFragment(['name' => 'Accra Central', 'is_active' => true])
            ->assertJsonMissing(['name' => 'Inactive Zone']); // inactive excluded
    }

    public function test_public_delivery_zones_excludes_inactive(): void
    {
        $response = $this->getJson('/api/v1/delivery-zones');
        $data = $response->json('data');

        // The public endpoint only returns active zones; Inactive Zone must not appear
        $names = array_column($data, 'name');
        $this->assertNotContains('Inactive Zone', $names);
        $this->assertContains('Accra Central', $names);
    }

    // ─── 2. Admin Delivery Zone Management ────────────────────────────────────

    public function test_admin_can_create_delivery_zone(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/delivery-zones', [
                'name'         => 'East Legon',
                'description'  => 'Upmarket residential area',
                'delivery_fee' => 20.00,
                'is_active'    => true,
            ]);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'East Legon']);

        $this->assertDatabaseHas('delivery_zones', [
            'name'         => 'East Legon',
            'delivery_fee' => '20.00',
        ]);
    }

    public function test_admin_can_update_delivery_zone(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/delivery-zones/{$this->activeZone->id}", [
                'delivery_fee' => 18.50,
            ]);

        $response->assertOk()
            ->assertJsonFragment(['delivery_fee' => '18.50']);
    }

    public function test_admin_can_toggle_zone_status(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/delivery-zones/{$this->activeZone->id}/status");

        $response->assertOk();
        $this->assertDatabaseHas('delivery_zones', [
            'id'        => $this->activeZone->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_delete_zone_with_no_orders(): void
    {
        $emptyZone = DeliveryZone::create([
            'name'         => 'Empty Zone',
            'delivery_fee' => 10.00,
            'is_active'    => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/delivery-zones/{$emptyZone->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('delivery_zones', ['id' => $emptyZone->id]);
    }

    public function test_admin_cannot_delete_zone_with_orders(): void
    {
        Order::create([
            'order_number'    => 'SD-TEST-001',
            'order_type'      => 'delivery',
            'delivery_zone_id'=> $this->activeZone->id,
            'customer_name'   => 'Test',
            'customer_phone'  => '0241111111',
            'delivery_address'=> 'Test Address',
            'status'          => 'pending',
            'subtotal'        => '35.00',
            'delivery_fee'    => '15.00',
            'total'           => '50.00',
            'payment_method'  => 'cash',
            'payment_status'  => 'pending',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/delivery-zones/{$this->activeZone->id}");

        $response->assertStatus(422);
    }

    public function test_delivery_staff_cannot_manage_zones(): void
    {
        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->postJson('/api/v1/admin/delivery-zones', [
                'name'         => 'Unauthorized Zone',
                'delivery_fee' => 10.00,
            ]);

        $response->assertForbidden();
    }

    public function test_zone_name_must_be_unique(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/delivery-zones', [
                'name'         => 'Accra Central', // duplicate
                'delivery_fee' => 15.00,
            ]);

        $response->assertUnprocessable();
    }

    // ─── 3. Delivery Order Creation ───────────────────────────────────────────

    public function test_customer_can_place_delivery_order(): void
    {
        Event::fake();
        $this->configureTestGateway();

        $response = $this->postJson('/api/v1/orders', $this->deliveryPayload(['payment_method' => 'gateway', 'customer_email' => 'guest@example.com']));

        $response->assertCreated()
            ->assertJsonFragment([
                'order_type'    => 'delivery',
                'payment_method'=> 'gateway',
                'payment_status'=> 'pending',
            ]);

        $data = $response->json('data');
        $this->assertEquals('85.00', $data['total']); // 2×35 + 15 fee
        $this->assertEquals('70.00', $data['subtotal']);
        $this->assertEquals('15.00', $data['delivery_fee']);
    }

    public function test_server_uses_database_delivery_fee_not_client_value(): void
    {
        Event::fake();
        $this->configureTestGateway();

        $payload = $this->deliveryPayload(['payment_method' => 'gateway', 'customer_email' => 'guest@example.com']);
        // Client tries to manipulate delivery fee — backend ignores it
        $payload['delivery_fee'] = 0.00;

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertEquals('15.00', $data['delivery_fee']); // DB value, not client value
        $this->assertEquals('85.00', $data['total']); // 70 + 15
    }

    public function test_customer_can_exclude_delivery_fee_from_order_total(): void
    {
        Event::fake();
        $this->configureTestGateway();
        $payload = $this->deliveryPayload(['payment_method' => 'gateway', 'customer_email' => 'guest@example.com', 'delivery_fee_payment_method' => 'cash_on_delivery']);
        $payload['include_delivery_fee'] = false;

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertEquals('70.00', $data['subtotal']);
        $this->assertEquals('15.00', $data['delivery_fee']);
        $this->assertEquals('70.00', $data['total']);
        $this->assertEquals('85.00', $data['grand_total']);
        $this->assertEquals('pending', $data['delivery_fee_payment_status']);
    }

    public function test_gateway_order_redirects_to_server_initialized_paystack_checkout(): void
    {
        Event::fake();
        PaymentGateway::create([
            'provider' => 'paystack', 'display_name' => 'Paystack',
            'public_key' => 'pk_test_public', 'secret_key' => 'sk_test_secret',
            'is_enabled' => true, 'is_default' => true,
        ]);
        Http::fake(['https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/test-session'],
        ], 200)]);
        $payload = $this->deliveryPayload(['payment_method' => 'gateway', 'customer_email' => 'guest@example.com']);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.payment_method', 'gateway')
            ->assertJsonPath('data.payment_checkout_url', 'https://checkout.paystack.com/test-session');
        $this->assertDatabaseHas('orders', ['order_number' => $response->json('data.order_number'), 'payment_status' => 'pending']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request['currency'] === 'GHS' && $request['amount'] === 8500);
    }

    public function test_paystack_payment_is_verified_against_amount_before_marking_order_paid(): void
    {
        Event::fake();
        PaymentGateway::create([
            'provider' => 'paystack', 'display_name' => 'Paystack',
            'public_key' => 'pk_test_public', 'secret_key' => 'sk_test_secret',
            'is_enabled' => true, 'is_default' => true,
        ]);
        Http::fakeSequence()
            ->push(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/test']], 200)
            ->push(['status' => true, 'data' => ['status' => 'success', 'reference' => 'REFERENCE', 'currency' => 'GHS', 'amount' => 8500]], 200);
        $payload = $this->deliveryPayload(['payment_method' => 'gateway', 'customer_email' => 'guest@example.com']);
        $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $order = Order::latest('id')->firstOrFail();
        $order->forceFill(['payment_reference' => 'REFERENCE'])->save();

        $response = $this->getJson('/api/v1/payments/verify/REFERENCE');

        $response->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_admin_gateway_secrets_are_encrypted_and_never_returned(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->putJson('/api/v1/admin/payment-gateway', [
            'public_key' => 'pk_test_public', 'secret_key' => 'sk_test_super_secret',
            'is_enabled' => true, 'is_default' => true,
        ]);

        $response->assertOk()->assertJsonPath('data.secret_key_configured', true);
        $this->assertStringNotContainsString('sk_test_super_secret', $response->getContent());
        $gateway = PaymentGateway::firstOrFail();
        $this->assertSame('sk_test_super_secret', $gateway->secret_key);
        $this->assertNotSame('sk_test_super_secret', $gateway->getRawOriginal('secret_key'));
    }

    public function test_server_uses_database_item_prices_not_client_values(): void
    {
        Event::fake();
        $this->configureTestGateway();

        $payload = $this->deliveryPayload(['payment_method' => 'gateway', 'customer_email' => 'guest@example.com']);
        $payload['items'][0]['unit_price'] = 0.01; // client price manipulation attempt

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        $data = $response->json('data');
        $item = $data['items'][0];
        $this->assertEquals('35.00', $item['unit_price']); // DB price
        $this->assertEquals('70.00', $item['line_total']);  // 2 × 35
    }

    public function test_delivery_order_requires_customer_name(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->deliveryPayload([
            'customer_name' => '',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_name']);
    }

    public function test_delivery_order_requires_customer_phone(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->deliveryPayload([
            'customer_phone' => '',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_phone']);
    }

    public function test_delivery_order_requires_delivery_address(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->deliveryPayload([
            'delivery_address' => '',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_address']);
    }

    public function test_delivery_order_requires_valid_active_zone(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->deliveryPayload([
            'delivery_zone_id' => 99999, // non-existent
        ]));

        $response->assertUnprocessable();
    }

    public function test_inactive_zone_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->deliveryPayload([
            'delivery_zone_id' => $this->inactiveZone->id,
        ]));

        $response->assertUnprocessable();
    }

    public function test_unavailable_menu_item_is_rejected(): void
    {
        $this->menuItem->update(['is_available' => false]);

        $response = $this->postJson('/api/v1/orders', $this->deliveryPayload());

        $response->assertUnprocessable();
    }

    public function test_delivery_order_requires_at_least_one_item(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->deliveryPayload([
            'items' => [],
        ]));

        $response->assertUnprocessable();
    }

    public function test_delivery_order_records_online_food_payment_as_pending_until_gateway_verification(): void
    {
        Event::fake();
        $this->configureTestGateway();

        $this->postJson('/api/v1/orders', $this->deliveryPayload(['payment_method' => 'gateway', 'customer_email' => 'guest@example.com']));

        $this->assertDatabaseHas('orders', [
            'order_type'     => 'delivery',
            'payment_method' => 'gateway',
            'payment_status' => 'pending',
        ]);
    }

    public function test_idempotent_delivery_order_submission(): void
    {
        Event::fake();
        $this->configureTestGateway();

        $payload = $this->deliveryPayload([
            'payment_method' => 'gateway',
            'customer_email' => 'guest@example.com',
            'idempotency_key' => 'test-idemp-key-' . uniqid(),
        ]);

        $res1 = $this->postJson('/api/v1/orders', $payload);
        $res2 = $this->postJson('/api/v1/orders', $payload);

        $res1->assertCreated();
        $res2->assertOk(); // idempotent — returns existing order

        $this->assertDatabaseCount('orders', 1);
    }

    // ─── 4. Delivery Staff Workflow ────────────────────────────────────────────

    public function test_delivery_staff_can_list_delivery_orders(): void
    {
        Event::fake();
        $this->configureTestGateway();
        $this->postJson('/api/v1/orders', $this->deliveryPayload(['payment_method' => 'gateway', 'customer_email' => 'guest@example.com']));

        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->getJson('/api/v1/delivery/orders');

        $response->assertOk()
            ->assertJsonStructure(['success', 'data', 'meta']);
    }

    public function test_unauthenticated_cannot_list_delivery_orders(): void
    {
        $response = $this->getJson('/api/v1/delivery/orders');
        $response->assertUnauthorized();
    }

    public function test_kitchen_staff_cannot_list_delivery_orders(): void
    {
        $response = $this->actingAs($this->kitchenStaff, 'sanctum')
            ->getJson('/api/v1/delivery/orders');

        $response->assertForbidden();
    }

    public function test_delivery_staff_can_assign_ready_delivery_order(): void
    {
        Event::fake();

        // Create and advance order to ready status
        $order = $this->createDeliveryOrderWithStatus('ready');

        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->postJson("/api/v1/delivery/orders/{$order->id}/assign");

        $response->assertOk()
            ->assertJsonFragment(['delivery_staff_id' => $this->deliveryStaff->id]);

        $this->assertDatabaseHas('orders', [
            'id'               => $order->id,
            'delivery_staff_id'=> $this->deliveryStaff->id,
        ]);
    }

    public function test_delivery_staff_cannot_assign_pending_order_to_self(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('pending');

        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->postJson("/api/v1/delivery/orders/{$order->id}/assign");

        $response->assertUnprocessable();
    }

    public function test_delivery_staff_cannot_steal_assigned_order(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('ready');
        $otherDeliveryStaff = $this->createUserWithRole('delivery-staff', 'other-delivery@test.com');

        // Assign to first delivery staff
        $order->update(['delivery_staff_id' => $this->deliveryStaff->id]);

        // Other delivery staff tries to steal it
        $response = $this->actingAs($otherDeliveryStaff, 'sanctum')
            ->postJson("/api/v1/delivery/orders/{$order->id}/assign");

        $response->assertUnprocessable();
    }

    public function test_delivery_staff_can_mark_order_out_for_delivery(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('ready');
        $order->update(['delivery_staff_id' => $this->deliveryStaff->id]);

        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->patchJson("/api/v1/delivery/orders/{$order->id}/status", [
                'status' => 'out_for_delivery',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['status' => 'out_for_delivery']);
    }

    public function test_delivery_staff_can_mark_order_delivered_with_payment_remaining_pending(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('out_for_delivery');
        $order->update(['delivery_staff_id' => $this->deliveryStaff->id]);

        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->patchJson("/api/v1/delivery/orders/{$order->id}/status", [
                'status' => 'delivered',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['status' => 'delivered']);

        // Business rule verification: Payment must REMAIN pending until cash is explicitly confirmed!
        $this->assertDatabaseHas('orders', [
            'id'             => $order->id,
            'status'         => 'delivered',
            'payment_status' => 'pending',
        ]);
    }

    public function test_delivery_staff_can_confirm_cash_payment_explicitly(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('delivered');
        $order->update(['delivery_staff_id' => $this->deliveryStaff->id]);

        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->postJson("/api/v1/delivery/orders/{$order->id}/confirm-payment", [
                'note' => 'Cash received in exact Cedis',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['payment_status' => 'paid']);

        $this->assertDatabaseHas('orders', [
            'id'             => $order->id,
            'payment_status' => 'paid',
        ]);

        // Verify audit trail entry was created
        $this->assertDatabaseHas('order_status_histories', [
            'order_id'   => $order->id,
            'changed_by' => $this->deliveryStaff->id,
            'note'       => 'Cash received in exact Cedis',
        ]);
    }

    public function test_delivery_staff_can_confirm_cash_during_delivery_transition(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('out_for_delivery');
        $order->update(['delivery_staff_id' => $this->deliveryStaff->id]);

        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->patchJson("/api/v1/delivery/orders/{$order->id}/status", [
                'status'         => 'delivered',
                'cash_collected' => true,
                'note'           => 'Delivered and cash collected at gate',
            ]);

        $response->assertOk()
            ->assertJsonFragment([
                'status'         => 'delivered',
                'payment_status' => 'paid',
            ]);

        $this->assertDatabaseHas('orders', [
            'id'             => $order->id,
            'status'         => 'delivered',
            'payment_status' => 'paid',
        ]);
    }

    public function test_duplicate_payment_confirmation_does_not_create_duplicate_audit_history(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('delivered');
        $order->update(['delivery_staff_id' => $this->deliveryStaff->id]);

        // First confirmation
        $this->actingAs($this->deliveryStaff, 'sanctum')
            ->postJson("/api/v1/delivery/orders/{$order->id}/confirm-payment", ['note' => 'First confirm']);

        $countAfterFirst = \App\Models\OrderStatusHistory::where('order_id', $order->id)->count();

        // Duplicate second confirmation
        $this->actingAs($this->deliveryStaff, 'sanctum')
            ->postJson("/api/v1/delivery/orders/{$order->id}/confirm-payment", ['note' => 'Second duplicate confirm']);

        $countAfterSecond = \App\Models\OrderStatusHistory::where('order_id', $order->id)->count();

        // Idempotency check: duplicate call must not add another history entry
        $this->assertEquals($countAfterFirst, $countAfterSecond);
    }

    public function test_delivery_order_cannot_be_completed_before_cash_payment_is_confirmed(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('delivered');
        $order->update(['delivery_staff_id' => $this->deliveryStaff->id]);
        // Payment remains pending

        // Attempt to complete order while payment is still pending
        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => 'completed',
            ]);

        $response->assertUnprocessable();
    }

    public function test_unauthorized_rider_cannot_confirm_payment_for_another_riders_order(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('delivered');
        $order->update(['delivery_staff_id' => $this->deliveryStaff->id]);

        $otherRider = $this->createUserWithRole('delivery-staff', 'rider2@test.com');

        $response = $this->actingAs($otherRider, 'sanctum')
            ->postJson("/api/v1/delivery/orders/{$order->id}/confirm-payment");

        $response->assertForbidden();
    }

    public function test_rider_cannot_access_unrelated_dine_in_orders(): void
    {
        $table = \App\Models\RestaurantTable::create(['table_number' => 'T99', 'capacity' => 2, 'is_active' => true]);
        $dineIn = Order::create([
            'order_number'        => 'SD-DINE-TEST',
            'order_type'          => 'dine_in',
            'restaurant_table_id' => $table->id,
            'customer_name'       => 'Dine Customer',
            'customer_phone'      => '0550000000',
            'status'              => 'confirmed',
            'subtotal'            => '35.00',
            'delivery_fee'        => '0.00',
            'total'               => '35.00',
            'payment_method'      => 'cash',
            'payment_status'      => 'pending',
        ]);

        // Rider calls /delivery/orders/{dineIn->id} -> 404 (not a delivery order)
        $this->actingAs($this->deliveryStaff, 'sanctum')
            ->getJson("/api/v1/delivery/orders/{$dineIn->id}")
            ->assertNotFound();

        // Rider calls /orders/{dineIn->id} -> 403 (rider cannot view dine-in)
        $this->actingAs($this->deliveryStaff, 'sanctum')
            ->getJson("/api/v1/orders/{$dineIn->id}")
            ->assertForbidden();
    }

    public function test_rider_cannot_access_another_riders_customer_details(): void
    {
        $otherRider = $this->createUserWithRole('delivery-staff', 'rider3@test.com');
        $order = $this->createDeliveryOrderWithStatus('out_for_delivery');
        $order->update(['delivery_staff_id' => $otherRider->id]);

        // DeliveryStaff (not assigned) attempts to view other rider's order
        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->getJson("/api/v1/delivery/orders/{$order->id}");

        $response->assertForbidden();
    }

    public function test_unassigned_pending_and_preparing_orders_not_visible_to_riders(): void
    {
        $pendingOrder = $this->createDeliveryOrderWithStatus('pending');
        $preparingOrder = $this->createDeliveryOrderWithStatus('preparing');
        $readyOrder = $this->createDeliveryOrderWithStatus('ready');

        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->getJson('/api/v1/delivery/orders');

        $response->assertOk();
        $orderNumbers = array_column($response->json('data'), 'order_number');

        // Only ready orders are visible for claim
        $this->assertContains($readyOrder->order_number, $orderNumbers);
        $this->assertNotContains($pendingOrder->order_number, $orderNumbers);
        $this->assertNotContains($preparingOrder->order_number, $orderNumbers);
    }

    public function test_privacy_realtime_event_does_not_broadcast_full_address_or_delivery_phone(): void
    {
        $order = $this->createDeliveryOrderWithStatus('out_for_delivery');
        $event = new \App\Events\OrderOutForDelivery($order);
        $payload = $event->broadcastWith();

        // Privacy assertion: Sensitive personal information is not exposed on WebSocket payloads
        $this->assertNull($payload['order']['delivery_address']);
        $this->assertNull($payload['order']['customer_phone']);
        $this->assertEquals('Accra Central', $payload['order']['delivery_zone_name']);
        $this->assertEquals($order->order_number, $payload['order']['order_number']);
    }

    public function test_invalid_delivery_status_transition_rejected(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('pending');
        $order->update(['delivery_staff_id' => $this->deliveryStaff->id]);

        // Can't go from pending to delivered directly
        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->patchJson("/api/v1/delivery/orders/{$order->id}/status", [
                'status' => 'delivered',
            ]);

        $response->assertUnprocessable();
    }

    public function test_delivery_staff_cannot_update_other_staffs_order(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('out_for_delivery');
        $order->update(['delivery_staff_id' => $this->admin->id]); // assigned to admin, not delivery staff

        $response = $this->actingAs($this->deliveryStaff, 'sanctum')
            ->patchJson("/api/v1/delivery/orders/{$order->id}/status", [
                'status' => 'delivered',
            ]);

        $response->assertForbidden();
    }

    public function test_admin_can_update_any_delivery_order(): void
    {
        Event::fake();
        $order = $this->createDeliveryOrderWithStatus('out_for_delivery');
        $order->update(['delivery_staff_id' => $this->deliveryStaff->id]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/delivery/orders/{$order->id}/status", [
                'status' => 'delivered',
            ]);

        $response->assertOk();
    }

    // ─── 5. Status History Tracking ───────────────────────────────────────────

    public function test_delivery_order_status_history_is_recorded(): void
    {
        Event::fake();
        $this->configureTestGateway();
        $this->postJson('/api/v1/orders', $this->deliveryPayload(['payment_method' => 'gateway', 'customer_email' => 'guest@example.com']));

        $order = Order::where('order_type', 'delivery')->firstOrFail();
        $this->assertDatabaseHas('order_status_histories', [
            'order_id'       => $order->id,
            'previous_status'=> null,
            'new_status'     => 'pending',
        ]);
    }

    // ─── 6. Regression: Existing Dine-in Still Works ──────────────────────────

    public function test_dine_in_order_creation_still_works_after_phase8(): void
    {
        Event::fake();

        // Create an active table
        $table = \App\Models\RestaurantTable::create([
            'table_number' => 'T1',
            'capacity'     => 4,
            'is_active'    => true,
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'order_type'           => 'dine_in',
            'customer_name'        => 'Ama Boateng',
            'customer_phone'       => '0551234567',
            'restaurant_table_id'  => $table->id,
            'items'                => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['order_type' => 'dine_in']);
    }

    public function test_home_delivery_rejects_cash_food_payment(): void
    {
        Event::fake();
        $payload = $this->deliveryPayload(['payment_method' => 'cash']);
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseCount('orders', 0);
    }

    // ─── 7. Order Model Constants ─────────────────────────────────────────────

    public function test_order_model_has_payment_constants(): void
    {
        $this->assertEquals('cash', Order::PAYMENT_METHOD_CASH);
        $this->assertEquals('pending', Order::PAYMENT_STATUS_PENDING);
        $this->assertEquals('paid', Order::PAYMENT_STATUS_PAID);
    }

    public function test_delivery_zone_model_has_description_field(): void
    {
        $zone = DeliveryZone::where('name', 'Accra Central')->first();
        $this->assertEquals('Central Accra business district', $zone->description);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createDeliveryOrderWithStatus(string $status): Order
    {
        $order = Order::create([
            'order_number'     => 'SD-TEST-' . strtoupper(uniqid()),
            'order_type'       => 'delivery',
            'delivery_zone_id' => $this->activeZone->id,
            'customer_name'    => 'Test Customer',
            'customer_phone'   => '0241234567',
            'delivery_address' => '15 Test Street, Accra',
            'status'           => $status,
            'subtotal'         => '70.00',
            'delivery_fee'     => '15.00',
            'total'            => '85.00',
            'payment_method'   => 'cash',
            'payment_status'   => 'pending',
        ]);

        $order->items()->create([
            'menu_item_id'  => $this->menuItem->id,
            'item_name'     => $this->menuItem->name,
            'unit_price'    => $this->menuItem->price,
            'quantity'      => 2,
            'line_total'    => '70.00',
        ]);

        $order->statusHistories()->create([
            'previous_status' => null,
            'new_status'      => $status,
            'changed_by'      => null,
            'note'            => 'Test order created at status: ' . $status,
        ]);

        return $order;
    }
}
