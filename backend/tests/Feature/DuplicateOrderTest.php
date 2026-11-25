<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateOrderTest extends TestCase
{
    use RefreshDatabase;

    private RestaurantTable $table;
    private MenuItem $item;
    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderService = new OrderService();

        $this->table = RestaurantTable::create([
            'table_number' => 'T-01',
            'capacity' => 4,
            'is_active' => true,
        ]);

        $category = MenuCategory::create([
            'name' => 'Main Courses',
            'slug' => 'main-courses',
            'is_active' => true,
        ]);

        $this->item = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Grilled Tilapia',
            'slug' => 'grilled-tilapia',
            'price' => 35.00,
            'is_available' => true,
        ]);
    }

    public function test_explicit_idempotency_key_handles_network_retries(): void
    {
        $payload = [
            'idempotency_key' => 'checkout-session-unique-12345',
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Kofi Mensah',
            'customer_phone' => '+233201234567',
            'items' => [
                ['menu_item_id' => $this->item->id, 'quantity' => 1],
            ],
        ];

        // Initial submission
        $order1 = $this->orderService->createOrder($payload);

        // Simulated network retry with identical key
        $order2 = $this->orderService->createOrder($payload);

        $this->assertEquals($order1->id, $order2->id);
        $this->assertEquals($order1->order_number, $order2->order_number);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_legitimate_subsequent_orders_with_distinct_keys_are_not_merged(): void
    {
        $payloadBase = [
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Kofi Mensah',
            'customer_phone' => '+233201234567',
            'items' => [
                ['menu_item_id' => $this->item->id, 'quantity' => 1],
            ],
        ];

        // First round order
        $order1 = $this->orderService->createOrder(array_merge($payloadBase, [
            'idempotency_key' => 'checkout-kofi-round-1',
        ]));

        // Second legitimate round order with identical items (e.g. for a friend arriving later)
        $order2 = $this->orderService->createOrder(array_merge($payloadBase, [
            'idempotency_key' => 'checkout-kofi-round-2',
        ]));

        $this->assertNotEquals($order1->id, $order2->id);
        $this->assertNotEquals($order1->order_number, $order2->order_number);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_api_endpoint_handles_idempotent_order_submission(): void
    {
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Ama Serwaa',
            'customer_phone' => '+233241234567',
            'items' => [
                ['menu_item_id' => $this->item->id, 'quantity' => 2],
            ],
        ];

        // First API request with header
        $response1 = $this->postJson('/api/v1/orders', $payload, [
            'X-Idempotency-Key' => 'req-idemp-key-abc-001',
        ]);
        $response1->assertStatus(201);
        $orderId = $response1->json('data.id');

        // Immediate duplicate request with same header
        $response2 = $this->postJson('/api/v1/orders', $payload, [
            'X-Idempotency-Key' => 'req-idemp-key-abc-001',
        ]);
        $response2->assertStatus(200);
        $this->assertEquals($orderId, $response2->json('data.id'));
        $this->assertEquals('Order already processed (idempotent submission).', $response2->json('message'));

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_rapid_double_click_without_key_is_detected_and_deduplicated(): void
    {
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Kwame Nkrumah',
            'customer_phone' => '+233501234567',
            'items' => [
                ['menu_item_id' => $this->item->id, 'quantity' => 1],
            ],
        ];

        // First click
        $order1 = $this->orderService->createOrder($payload);

        // Immediate second click with identical payload (no explicit key)
        $order2 = $this->orderService->createOrder($payload);

        $this->assertEquals($order1->id, $order2->id);
        $this->assertDatabaseCount('orders', 1);
    }
}
