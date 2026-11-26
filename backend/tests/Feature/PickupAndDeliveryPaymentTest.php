<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickupAndDeliveryPaymentTest extends TestCase
{
    use RefreshDatabase;

    private MenuItem $item;
    private DeliveryZone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $category = MenuCategory::create(['name' => 'Meals', 'slug' => 'meals']);
        $this->item = MenuItem::create(['category_id' => $category->id, 'name' => 'Rice', 'slug' => 'rice', 'price' => '150.00', 'is_available' => true]);
        $this->zone = DeliveryZone::create(['name' => 'Central', 'delivery_fee' => '15.00', 'is_active' => true]);
    }

    public function test_guest_can_create_pickup_order_without_delivery_fields_or_fee(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'order_type' => 'pickup', 'payment_method' => 'cash', 'customer_name' => 'Guest Name', 'customer_phone' => '0240000000',
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.order_type', 'pickup')->assertJsonPath('data.delivery_fee', '0.00')->assertJsonPath('data.total', '150.00');

        $this->assertNull($response->json('data.delivery_zone_id'));
        $this->assertNull($response->json('data.delivery_address'));
        $this->assertSame('not_applicable', $response->json('data.delivery_fee_payment_status'));
    }

    public function test_public_delivery_cannot_select_cash_for_food(): void
    {
        $this->postJson('/api/v1/orders', [
            'order_type' => 'delivery', 'payment_method' => 'cash', 'delivery_zone_id' => $this->zone->id,
            'delivery_address' => 'House 5, Central Road, near the market', 'customer_name' => 'Guest Name', 'customer_phone' => '0240000000',
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_delivery_fee_cash_receipt_is_separate_and_only_recorded_after_delivery(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());
        $order = app(OrderService::class)->createOrder([
            'order_type' => 'delivery', 'payment_method' => 'gateway', 'delivery_zone_id' => $this->zone->id,
            'delivery_fee_payment_method' => 'cash_on_delivery', 'delivery_address' => 'House 5, Central Road, near the market',
            'customer_name' => 'Guest Name', 'customer_phone' => '0240000000', 'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
        ]);
        $this->assertSame('150.00', $order->total); // gateway charge is the food amount only
        $this->assertSame('165.00', $order->grand_total);
        $this->assertSame('pending', $order->delivery_fee_payment_status);

        try {
            app(OrderService::class)->confirmDeliveryFeeCash($order, $user);
            $this->fail('A cash delivery fee must not be confirmed before the delivery is marked delivered.');
        } catch (\DomainException) {
            $this->assertDatabaseCount('payment_transactions', 0);
        }

        $order->forceFill(['status' => Order::STATUS_DELIVERED, 'payment_status' => Order::PAYMENT_STATUS_PAID])->save();
        $confirmed = app(OrderService::class)->confirmDeliveryFeeCash($order, $user);
        $this->assertSame('paid', $confirmed->delivery_fee_payment_status);
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'transaction_reference' => 'DELIVERY-FEE-CASH-'.$order->order_number,
            'payment_method' => 'cash',
            'amount' => '15.00',
            'status' => 'succeeded',
            'confirmed_by' => $user->id,
        ]);
        app(OrderService::class)->confirmDeliveryFeeCash($confirmed, $user);
        $this->assertDatabaseCount('payment_transactions', 1);
    }
}
