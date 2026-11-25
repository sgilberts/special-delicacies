<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orderService;
    private MenuCategory $category;
    private MenuItem $item1;
    private MenuItem $item2;
    private RestaurantTable $table;
    private DeliveryZone $zone;
    private User $adminUser;
    private User $waiterUser;
    private User $kitchenUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->orderService = new OrderService();

        $this->category = MenuCategory::create([
            'name' => 'Main Dishes',
            'slug' => 'main-dishes',
        ]);

        $this->item1 = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Roast Duck',
            'slug' => 'roast-duck',
            'price' => 20.00,
            'is_available' => true,
        ]);

        $this->item2 = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Dumplings',
            'slug' => 'dumplings',
            'price' => 8.50,
            'is_available' => true,
        ]);

        $this->table = RestaurantTable::create([
            'table_number' => 'T-10',
            'capacity' => 4,
            'is_active' => true,
        ]);

        $this->zone = DeliveryZone::create([
            'name' => 'Central City',
            'delivery_fee' => 5.00,
            'is_active' => true,
        ]);

        $adminRole = Role::where('slug', 'super-admin')->firstOrFail();
        $waiterRole = Role::where('slug', 'waiter-waitress')->firstOrFail();
        $kitchenRole = Role::where('slug', 'kitchen-staff')->firstOrFail();

        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->attach($adminRole);

        $this->waiterUser = User::factory()->create();
        $this->waiterUser->roles()->attach($waiterRole);

        $this->kitchenUser = User::factory()->create();
        $this->kitchenUser->roles()->attach($kitchenRole);
    }

    public function test_can_create_dine_in_order_with_server_price_snapshots(): void
    {
        $order = $this->orderService->createOrder([
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Alice Smith',
            'customer_phone' => '555-0100',
            'items' => [
                ['menu_item_id' => $this->item1->id, 'quantity' => 2],
                ['menu_item_id' => $this->item2->id, 'quantity' => 1, 'special_instructions' => 'Extra sauce'],
            ],
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_type' => 'dine_in',
            'restaurant_table_id' => $this->table->id,
            'status' => 'pending',
            'subtotal' => '48.50', // (20 * 2) + (8.50 * 1)
            'delivery_fee' => '0.00',
            'total' => '48.50',
        ]);

        $this->assertCount(2, $order->items);
        $this->assertEquals('Roast Duck', $order->items[0]->item_name);
        $this->assertEquals('20.00', $order->items[0]->unit_price);
        $this->assertEquals('40.00', $order->items[0]->line_total);

        // Verify status history
        $this->assertCount(1, $order->statusHistories);
        $this->assertEquals('pending', $order->statusHistories[0]->new_status);
    }

    public function test_client_cannot_manipulate_menu_prices(): void
    {
        // Even if an attacker passes 'price' => 1.00 in the request
        $order = $this->orderService->createOrder([
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Attacker',
            'customer_phone' => '555-0999',
            'items' => [
                ['menu_item_id' => $this->item1->id, 'quantity' => 1, 'price' => 1.00],
            ],
        ]);

        // Server must enforce real DB price (20.00)
        $this->assertEquals('20.00', $order->subtotal);
        $this->assertEquals('20.00', $order->items->first()->unit_price);
    }

    public function test_cannot_order_unavailable_menu_item(): void
    {
        $unavailableItem = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Sold Out Seafood',
            'slug' => 'sold-out-seafood',
            'price' => 35.00,
            'is_available' => false,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Menu item 'Sold Out Seafood' is currently unavailable.");

        $this->orderService->createOrder([
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Bob',
            'customer_phone' => '555-0200',
            'items' => [
                ['menu_item_id' => $unavailableItem->id, 'quantity' => 1],
            ],
        ]);
    }

    public function test_can_create_delivery_order_with_separate_delivery_fee(): void
    {
        $order = $this->orderService->createOrder([
            'order_type' => Order::TYPE_DELIVERY,
            'delivery_zone_id' => $this->zone->id,
            'delivery_address' => '123 Main St, Apt 4B',
            'customer_name' => 'Carol Danvers',
            'customer_phone' => '555-0300',
            'items' => [
                ['menu_item_id' => $this->item1->id, 'quantity' => 1],
            ],
        ]);

        $this->assertEquals('20.00', $order->subtotal);
        $this->assertEquals('5.00', $order->delivery_fee);
        $this->assertEquals('25.00', $order->total);
        $this->assertEquals('123 Main St, Apt 4B', $order->delivery_address);
    }

    public function test_dine_in_order_lifecycle_transitions(): void
    {
        $order = $this->orderService->createOrder([
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'David',
            'customer_phone' => '555-0400',
            'items' => [
                ['menu_item_id' => $this->item1->id, 'quantity' => 1],
            ],
        ]);

        // Pending -> Confirmed (waiter confirms)
        $order = $this->orderService->updateStatus($order, Order::STATUS_CONFIRMED, $this->waiterUser, 'Order confirmed by waiter');
        $this->assertEquals('confirmed', $order->status);

        // Confirmed -> Preparing (kitchen starts)
        $order = $this->orderService->updateStatus($order, Order::STATUS_PREPARING, $this->kitchenUser, 'Kitchen cooking');
        $this->assertEquals('preparing', $order->status);

        // Preparing -> Ready (kitchen marks ready)
        $order = $this->orderService->updateStatus($order, Order::STATUS_READY, $this->kitchenUser, 'Ready for pickup');
        $this->assertEquals('ready', $order->status);

        // Ready -> Delivered (waiter delivers)
        $order = $this->orderService->updateStatus($order, Order::STATUS_DELIVERED, $this->waiterUser, 'Served to table');
        $this->assertEquals('delivered', $order->status);

        // Delivered -> Completed (waiter completes)
        $order = $this->orderService->updateStatus($order, Order::STATUS_COMPLETED, $this->waiterUser, 'Table completed');
        $this->assertEquals('completed', $order->status);

        $this->assertCount(6, $order->statusHistories); // Initial pending + 5 transitions
    }

    public function test_delivery_order_lifecycle_includes_out_for_delivery(): void
    {
        $order = $this->orderService->createOrder([
            'order_type' => Order::TYPE_DELIVERY,
            'delivery_zone_id' => $this->zone->id,
            'delivery_address' => '456 Oak Avenue',
            'customer_name' => 'Emma',
            'customer_phone' => '555-0500',
            'items' => [
                ['menu_item_id' => $this->item1->id, 'quantity' => 1],
            ],
        ]);

        $order = $this->orderService->updateStatus($order, Order::STATUS_CONFIRMED, $this->adminUser);
        $order = $this->orderService->updateStatus($order, Order::STATUS_PREPARING, $this->adminUser);
        $order = $this->orderService->updateStatus($order, Order::STATUS_READY, $this->adminUser);

        // Ready -> Out for delivery
        $order = $this->orderService->updateStatus($order, Order::STATUS_OUT_FOR_DELIVERY, $this->adminUser);
        $this->assertEquals('out_for_delivery', $order->status);

        // Out for delivery -> Delivered
        $order = $this->orderService->updateStatus($order, Order::STATUS_DELIVERED, $this->adminUser);
        $this->assertEquals('delivered', $order->status);

        // Confirm cash payment before completing delivery order
        $order = $this->orderService->confirmPayment($order, $this->adminUser);
        $this->assertEquals('paid', $order->payment_status);

        // Delivered -> Completed
        $order = $this->orderService->updateStatus($order, Order::STATUS_COMPLETED, $this->adminUser);
        $this->assertEquals('completed', $order->status);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $order = $this->orderService->createOrder([
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Frank',
            'customer_phone' => '555-0600',
            'items' => [
                ['menu_item_id' => $this->item1->id, 'quantity' => 1],
            ],
        ]);

        // Attempting to jump directly from pending to completed must fail
        $this->expectException(DomainException::class);
        $this->orderService->updateStatus($order, Order::STATUS_COMPLETED, $this->adminUser);
    }

    public function test_order_can_be_cancelled_from_pending_or_confirmed(): void
    {
        $order = $this->orderService->createOrder([
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'George',
            'customer_phone' => '555-0700',
            'items' => [
                ['menu_item_id' => $this->item1->id, 'quantity' => 1],
            ],
        ]);

        $order = $this->orderService->updateStatus($order, Order::STATUS_CANCELLED, $this->adminUser, 'Customer cancelled');
        $this->assertEquals('cancelled', $order->status);
    }

    public function test_public_tracking_returns_order_progress_without_internal_ids_or_customer_details(): void
    {
        $order = $this->orderService->createOrder([
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Private Customer',
            'customer_phone' => '555-0800',
            'items' => [['menu_item_id' => $this->item1->id, 'quantity' => 2]],
        ]);

        $this->assertLessThanOrEqual(32, strlen($order->order_number));
        $this->assertMatchesRegularExpression('/^SD-\d{8}-[A-F0-9]{16}$/', $order->order_number);
        $response = $this->getJson('/api/v1/orders/track/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.order_type', Order::TYPE_DINE_IN)
            ->assertJsonPath('data.table.table_number', $this->table->table_number)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.status_history.0.status', Order::STATUS_PENDING)
            ->assertJsonMissingPath('data.order_id')
            ->assertJsonMissingPath('data.customer_phone');

        $this->assertStringNotContainsString('Private Customer', $response->getContent());
    }
}
