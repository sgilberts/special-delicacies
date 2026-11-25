<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\Role;
use App\Models\RestaurantTable;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7 — Real-Time Restaurant Operations
 *
 * Tests for role-based order management API access and channel authorization.
 */
class Phase7OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $waiterUser;
    protected User $kitchenUser;
    protected Order $order;
    protected RestaurantTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->adminUser = User::factory()->create(['is_active' => true]);
        $this->adminUser->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());

        $this->waiterUser = User::factory()->create(['is_active' => true]);
        $this->waiterUser->roles()->attach(Role::where('slug', 'waiter-waitress')->firstOrFail());

        $this->kitchenUser = User::factory()->create(['is_active' => true]);
        $this->kitchenUser->roles()->attach(Role::where('slug', 'kitchen-staff')->firstOrFail());

        // Create a table and pending dine-in order for lifecycle tests
        $this->table = RestaurantTable::create([
            'table_number' => 'T1',
            'capacity' => 4,
            'is_active' => true,
        ]);

        $category = MenuCategory::create([
            'name' => 'Test Category',
            'slug' => 'test-category',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Test Item',
            'slug' => 'test-item',
            'price' => '10.00',
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $this->order = Order::create([
            'order_number' => 'SD-TEST-001',
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Test Customer',
            'customer_phone' => '0501234567',
            'status' => Order::STATUS_PENDING,
            'subtotal' => '10.00',
            'delivery_fee' => '0.00',
            'total' => '10.00',
        ]);

        $this->order->items()->create([
            'menu_item_id' => $menuItem->id,
            'item_name' => 'Test Item',
            'unit_price' => '10.00',
            'quantity' => 1,
            'line_total' => '10.00',
        ]);

        $this->order->statusHistories()->create([
            'previous_status' => null,
            'new_status' => Order::STATUS_PENDING,
            'changed_by' => null,
            'note' => 'Order placed.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Orders listing access
    // ─────────────────────────────────────────────────────────────────────────

    public function test_waiter_can_list_orders(): void
    {
        $response = $this->actingAs($this->waiterUser, 'sanctum')
            ->getJson('/api/v1/orders');

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);
    }

    public function test_kitchen_can_list_orders(): void
    {
        $response = $this->actingAs($this->kitchenUser, 'sanctum')
            ->getJson('/api/v1/orders');

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);
    }

    public function test_unauthenticated_cannot_list_orders(): void
    {
        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(401);
    }

    public function test_inactive_user_cannot_list_orders(): void
    {
        $this->waiterUser->update(['is_active' => false]);

        $response = $this->actingAs($this->waiterUser, 'sanctum')
            ->getJson('/api/v1/orders');

        // EnsureUserHasPermission returns 403 for inactive users
        $response->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Order status transitions — waiter actions
    // ─────────────────────────────────────────────────────────────────────────

    public function test_waiter_can_confirm_pending_order(): void
    {
        $response = $this->actingAs($this->waiterUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$this->order->id}/status", [
                'status' => Order::STATUS_CONFIRMED,
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);

        $this->assertDatabaseHas('orders', [
            'id' => $this->order->id,
            'status' => Order::STATUS_CONFIRMED,
        ]);
    }

    public function test_kitchen_cannot_confirm_order(): void
    {
        // Kitchen staff lacks orders.confirm and orders.status.update — should be blocked
        $response = $this->actingAs($this->kitchenUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$this->order->id}/status", [
                'status' => Order::STATUS_CONFIRMED,
            ]);

        $response->assertStatus(403);
    }

    public function test_waiter_can_deliver_ready_order(): void
    {
        // Move through the lifecycle to reach READY
        $this->order->update(['status' => Order::STATUS_READY]);

        $response = $this->actingAs($this->waiterUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$this->order->id}/status", [
                'status' => Order::STATUS_DELIVERED,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id' => $this->order->id,
            'status' => Order::STATUS_DELIVERED,
        ]);
    }

    public function test_waiter_can_complete_delivered_order(): void
    {
        $this->order->update(['status' => Order::STATUS_DELIVERED]);

        $response = $this->actingAs($this->waiterUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$this->order->id}/status", [
                'status' => Order::STATUS_COMPLETED,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id' => $this->order->id,
            'status' => Order::STATUS_COMPLETED,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Kitchen staff transitions
    // ─────────────────────────────────────────────────────────────────────────

    public function test_kitchen_can_start_preparing(): void
    {
        $this->order->update(['status' => Order::STATUS_CONFIRMED]);

        $response = $this->actingAs($this->kitchenUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$this->order->id}/status", [
                'status' => Order::STATUS_PREPARING,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id' => $this->order->id,
            'status' => Order::STATUS_PREPARING,
        ]);
    }

    public function test_kitchen_can_mark_ready(): void
    {
        $this->order->update(['status' => Order::STATUS_PREPARING]);

        $response = $this->actingAs($this->kitchenUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$this->order->id}/status", [
                'status' => Order::STATUS_READY,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id' => $this->order->id,
            'status' => Order::STATUS_READY,
        ]);
    }

    public function test_kitchen_cannot_deliver_order(): void
    {
        $this->order->update(['status' => Order::STATUS_READY]);

        $response = $this->actingAs($this->kitchenUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$this->order->id}/status", [
                'status' => Order::STATUS_DELIVERED,
            ]);

        // Kitchen has orders.ready permission which passes middleware but
        // authorizeStatusTransition requires orders.deliver → returns 403
        $response->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Invalid / unauthorized transitions
    // ─────────────────────────────────────────────────────────────────────────

    public function test_invalid_transition_rejected(): void
    {
        // pending → completed is not a valid lifecycle transition
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$this->order->id}/status", [
                'status' => Order::STATUS_COMPLETED,
            ]);

        $response->assertStatus(422);

        // Order must remain pending
        $this->assertDatabaseHas('orders', [
            'id' => $this->order->id,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Status history recording
    // ─────────────────────────────────────────────────────────────────────────

    public function test_each_transition_records_status_history(): void
    {
        $historyCountBefore = $this->order->statusHistories()->count();

        $this->actingAs($this->waiterUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$this->order->id}/status", [
                'status' => Order::STATUS_CONFIRMED,
            ]);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $this->order->id,
            'previous_status' => Order::STATUS_PENDING,
            'new_status' => Order::STATUS_CONFIRMED,
        ]);

        $this->assertEquals(
            $historyCountBefore + 1,
            $this->order->fresh()->statusHistories()->count()
        );
    }

    public function test_failed_transition_no_history_added(): void
    {
        $historyCountBefore = $this->order->statusHistories()->count();

        // Invalid transition — no history should be added
        $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$this->order->id}/status", [
                'status' => Order::STATUS_COMPLETED,
            ]);

        $this->assertEquals(
            $historyCountBefore,
            $this->order->fresh()->statusHistories()->count()
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Broadcasting channel authorization
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Channel authorization tests use the broadcasting route directly.
     * With BROADCAST_CONNECTION=null in tests, the HTTP auth endpoint returns
     * an empty 200 for all authenticated users regardless of channel callbacks
     * (NullBroadcaster::auth() is a no-op). We therefore test channel-callback
     * logic via the BroadcastManager's channel resolver instead.
     */
    public function test_waiter_can_subscribe_to_waiter_channel(): void
    {
        $result = $this->resolveChannel('orders.waiter', $this->waiterUser);
        $this->assertTrue((bool) $result, 'Waiter should be authorized for orders.waiter channel.');
    }

    public function test_kitchen_can_subscribe_to_kitchen_channel(): void
    {
        $result = $this->resolveChannel('orders.kitchen', $this->kitchenUser);
        $this->assertTrue((bool) $result, 'Kitchen staff should be authorized for orders.kitchen channel.');
    }

    public function test_kitchen_cannot_subscribe_to_waiter_channel(): void
    {
        $result = $this->resolveChannel('orders.waiter', $this->kitchenUser);
        $this->assertFalse((bool) $result, 'Kitchen staff should NOT be authorized for orders.waiter channel.');
    }

    public function test_unauthenticated_cannot_subscribe_to_private_channels(): void
    {
        // Unauthenticated users fail at the auth:sanctum middleware layer
        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-orders.waiter',
        ]);

        $response->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Directly resolve a registered broadcast channel callback for the given user.
     * This bypasses the HTTP layer and tests authorization logic independently
     * of the configured broadcaster driver.
     *
     * @return mixed  Whatever the channel callback returns (true/false/array)
     */
    private function resolveChannel(string $channelName, User $user): mixed
    {
        /** @var \Illuminate\Broadcasting\BroadcastManager $manager */
        $manager = app(\Illuminate\Broadcasting\BroadcastManager::class);
        $broadcaster = $manager->connection();

        // Access the protected channels array via reflection
        $reflection = new \ReflectionObject($broadcaster);
        $channelsProp = $reflection->getProperty('channels');
        $channelsProp->setAccessible(true);
        $channels = $channelsProp->getValue($broadcaster);

        foreach ($channels as $pattern => $callback) {
            // The pattern is registered without 'private-' prefix in channels.php
            if ($pattern === $channelName) {
                if (is_callable($callback)) {
                    return $callback($user);
                }
            }
        }

        // No matching channel — treat as unauthorized
        return false;
    }
}
