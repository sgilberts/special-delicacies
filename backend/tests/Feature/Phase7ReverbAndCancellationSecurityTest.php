<?php

namespace Tests\Feature;

use App\Events\OrderCancelled;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Phase 7 — Reverb Administration, Process Security, and Order Cancellation Test Suite
 */
class Phase7ReverbAndCancellationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $waiterUser;
    protected User $kitchenUser;
    protected User $inactiveAdmin;
    protected RestaurantTable $table;
    protected MenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $adminRole = Role::where('slug', 'super-admin')->firstOrFail();
        $waiterRole = Role::where('slug', 'waiter-waitress')->firstOrFail();
        $kitchenRole = Role::where('slug', 'kitchen-staff')->firstOrFail();

        $this->adminUser = User::factory()->create(['is_active' => true]);
        $this->adminUser->roles()->attach($adminRole);

        $this->waiterUser = User::factory()->create(['is_active' => true]);
        $this->waiterUser->roles()->attach($waiterRole);

        $this->kitchenUser = User::factory()->create(['is_active' => true]);
        $this->kitchenUser->roles()->attach($kitchenRole);

        $this->inactiveAdmin = User::factory()->create(['is_active' => false]);
        $this->inactiveAdmin->roles()->attach($adminRole);

        $this->table = RestaurantTable::create([
            'table_number' => 'T10',
            'capacity' => 4,
            'is_active' => true,
        ]);

        $category = MenuCategory::create([
            'name' => 'Specials',
            'slug' => 'specials',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Jollof Feast',
            'slug' => 'jollof-feast',
            'price' => 75.00,
            'is_available' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Reverb Administration Endpoints Security & Authorization
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unauthenticated_requests_to_reverb_endpoints_are_rejected(): void
    {
        $this->getJson('/api/v1/admin/reverb/status')->assertStatus(401);
        $this->postJson('/api/v1/admin/reverb/start')->assertStatus(401);
        $this->postJson('/api/v1/admin/reverb/stop')->assertStatus(401);
        $this->postJson('/api/v1/admin/reverb/restart')->assertStatus(401);
        $this->postJson('/api/v1/admin/reverb/test-broadcast')->assertStatus(401);
    }

    public function test_reverb_process_identity_requires_this_projects_absolute_artisan_command(): void
    {
        $controller = app(\App\Http\Controllers\Api\V1\Admin\ReverbController::class);
        $method = new \ReflectionMethod($controller, 'isManagedReverbCommand');
        $method->setAccessible(true);
        $artisan = str_replace('\\', '/', base_path('artisan'));

        $this->assertTrue($method->invoke($controller, 'php "'.$artisan.'" reverb:start --host=0.0.0.0 --port=8082'));
        $this->assertFalse($method->invoke($controller, 'php artisan reverb:start --host=0.0.0.0 --port=8082'));
        $this->assertFalse($method->invoke($controller, 'php "C:/other-project/artisan" reverb:start --host=0.0.0.0 --port=8082'));
        $this->assertFalse($method->invoke($controller, 'php "'.$artisan.'" serve --host=0.0.0.0'));
    }

    public function test_deactivated_admin_is_rejected_from_reverb_endpoints(): void
    {
        $this->actingAs($this->inactiveAdmin, 'sanctum')
            ->getJson('/api/v1/admin/reverb/status')
            ->assertStatus(403);

        $this->actingAs($this->inactiveAdmin, 'sanctum')
            ->postJson('/api/v1/admin/reverb/start')
            ->assertStatus(403);
    }

    public function test_waiter_cannot_control_reverb_endpoints(): void
    {
        $this->actingAs($this->waiterUser, 'sanctum')
            ->getJson('/api/v1/admin/reverb/status')
            ->assertStatus(403);

        $this->actingAs($this->waiterUser, 'sanctum')
            ->postJson('/api/v1/admin/reverb/start')
            ->assertStatus(403);

        $this->actingAs($this->waiterUser, 'sanctum')
            ->postJson('/api/v1/admin/reverb/stop')
            ->assertStatus(403);

        $this->actingAs($this->waiterUser, 'sanctum')
            ->postJson('/api/v1/admin/reverb/restart')
            ->assertStatus(403);

        $this->actingAs($this->waiterUser, 'sanctum')
            ->postJson('/api/v1/admin/reverb/test-broadcast')
            ->assertStatus(403);
    }

    public function test_kitchen_staff_cannot_control_reverb_endpoints(): void
    {
        $this->actingAs($this->kitchenUser, 'sanctum')
            ->getJson('/api/v1/admin/reverb/status')
            ->assertStatus(403);

        $this->actingAs($this->kitchenUser, 'sanctum')
            ->postJson('/api/v1/admin/reverb/start')
            ->assertStatus(403);

        $this->actingAs($this->kitchenUser, 'sanctum')
            ->postJson('/api/v1/admin/reverb/stop')
            ->assertStatus(403);
    }

    public function test_admin_can_access_reverb_status_without_leaking_secrets(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/admin/reverb/status');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'is_running',
                'pid',
                'host',
                'port',
                'scheme',
                'app_id',
                'app_key',
                'memory',
                'uptime',
                'server_time',
            ],
        ]);

        $json = $response->json();
        // Crucial security checks: Ensure no app secret or credentials are in the status payload
        $this->assertArrayNotHasKey('app_secret', $json['data']);
        $this->assertArrayNotHasKey('secret', $json['data']);
        $this->assertArrayNotHasKey('password', $json['data']);
        $this->assertArrayNotHasKey('token', $json['data']);
    }

    public function test_process_parameters_cannot_be_injected_via_request(): void
    {
        // Attempt to pass arbitrary PID to stop
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/admin/reverb/stop', [
                'pid' => 1, // Attempt to kill system process
            ]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));

        // Attempt to pass arbitrary host or command to start
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/admin/reverb/start', [
                'cmd' => 'malicious_command',
                'host' => '0.0.0.0',
            ]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
    }

    public function test_reverb_monitor_channel_is_restricted_to_active_admins(): void
    {
        $adminRole = Role::where('slug', 'admin')->firstOrFail();
        $monitorAdmin = User::factory()->create(['is_active' => true]);
        $monitorAdmin->roles()->attach($adminRole);

        $adminAllowed = $this->resolveChannel('reverb.monitor', $monitorAdmin);
        $this->assertFalse((bool) $adminAllowed, 'An administrator without the explicit monitor permission must not subscribe.');

        $adminRole->givePermissionTo('reverb.monitor');
        $monitorAdmin->unsetRelation('roles');
        $authorizedAdmin = $this->resolveChannel('reverb.monitor', $monitorAdmin);
        $this->assertTrue((bool) $authorizedAdmin, 'Active administrators with reverb.monitor may subscribe.');

        $waiterAllowed = $this->resolveChannel('reverb.monitor', $this->waiterUser);
        $this->assertFalse((bool) $waiterAllowed, 'Waiter must NOT be authorized for reverb.monitor channel.');

        $kitchenAllowed = $this->resolveChannel('reverb.monitor', $this->kitchenUser);
        $this->assertFalse((bool) $kitchenAllowed, 'Kitchen must NOT be authorized for reverb.monitor channel.');

        $inactiveAllowed = $this->resolveChannel('reverb.monitor', $this->inactiveAdmin);
        $this->assertFalse((bool) $inactiveAllowed, 'Inactive admin must NOT be authorized for reverb.monitor channel.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Order Cancellation Workflow and Security
    // ─────────────────────────────────────────────────────────────────────────

    public function test_waiter_can_cancel_pending_dine_in_order(): void
    {
        Event::fake([OrderCancelled::class]);

        $order = $this->createTestOrder(Order::STATUS_PENDING);

        $response = $this->actingAs($this->waiterUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => Order::STATUS_CANCELLED,
                'notes' => 'Customer changed mind before preparation',
            ]);

        $response->assertStatus(200);
        $this->assertEquals(Order::STATUS_CANCELLED, $order->fresh()->status);

        // Verify status history entry
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'previous_status' => Order::STATUS_PENDING,
            'new_status' => Order::STATUS_CANCELLED,
            'changed_by' => $this->waiterUser->id,
        ]);

        // Verify OrderCancelled event was broadcast
        Event::assertDispatched(OrderCancelled::class, function ($event) use ($order) {
            return $event->order->id === $order->id;
        });
    }

    public function test_admin_can_cancel_confirmed_dine_in_order(): void
    {
        Event::fake([OrderCancelled::class]);

        $order = $this->createTestOrder(Order::STATUS_CONFIRMED);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => Order::STATUS_CANCELLED,
                'notes' => 'Cancelled by manager',
            ]);

        $response->assertStatus(200);
        $this->assertEquals(Order::STATUS_CANCELLED, $order->fresh()->status);

        Event::assertDispatched(OrderCancelled::class);
    }

    public function test_kitchen_staff_cannot_cancel_orders(): void
    {
        $order = $this->createTestOrder(Order::STATUS_CONFIRMED);

        $response = $this->actingAs($this->kitchenUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => Order::STATUS_CANCELLED,
            ]);

        $response->assertStatus(403);
        $this->assertEquals(Order::STATUS_CONFIRMED, $order->fresh()->status);
    }

    public function test_cannot_cancel_order_already_in_preparation(): void
    {
        $order = $this->createTestOrder(Order::STATUS_PREPARING);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => Order::STATUS_CANCELLED,
            ]);

        $response->assertStatus(422);
        $this->assertEquals(Order::STATUS_PREPARING, $order->fresh()->status);
    }

    public function test_cannot_cancel_order_that_is_ready_or_completed(): void
    {
        $readyOrder = $this->createTestOrder(Order::STATUS_READY);
        $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$readyOrder->id}/status", [
                'status' => Order::STATUS_CANCELLED,
            ])
            ->assertStatus(422);

        $completedOrder = $this->createTestOrder(Order::STATUS_COMPLETED);
        $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/orders/{$completedOrder->id}/status", [
                'status' => Order::STATUS_CANCELLED,
            ])
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Granular Private Channel (orders.{orderId}) Operational Authorization
    // ─────────────────────────────────────────────────────────────────────────

    public function test_order_channel_allows_waiter_for_active_dine_in_orders(): void
    {
        $pendingOrder = $this->createTestOrder(Order::STATUS_PENDING);
        $this->assertTrue((bool) $this->resolveOrderChannel($pendingOrder->id, $this->waiterUser));

        $preparingOrder = $this->createTestOrder(Order::STATUS_PREPARING);
        $this->assertTrue((bool) $this->resolveOrderChannel($preparingOrder->id, $this->waiterUser));

        $deliveredOrder = $this->createTestOrder(Order::STATUS_DELIVERED);
        $this->assertTrue((bool) $this->resolveOrderChannel($deliveredOrder->id, $this->waiterUser));
    }

    public function test_order_channel_restricts_waiter_from_completed_orders_not_assigned(): void
    {
        $completedOrder = $this->createTestOrder(Order::STATUS_COMPLETED);
        $this->assertFalse((bool) $this->resolveOrderChannel($completedOrder->id, $this->waiterUser));
    }

    public function test_order_channel_restricts_kitchen_staff_to_active_kitchen_workflow(): void
    {
        $confirmedOrder = $this->createTestOrder(Order::STATUS_CONFIRMED);
        $this->assertTrue((bool) $this->resolveOrderChannel($confirmedOrder->id, $this->kitchenUser));

        $preparingOrder = $this->createTestOrder(Order::STATUS_PREPARING);
        $this->assertTrue((bool) $this->resolveOrderChannel($preparingOrder->id, $this->kitchenUser));

        $readyOrder = $this->createTestOrder(Order::STATUS_READY);
        $this->assertTrue((bool) $this->resolveOrderChannel($readyOrder->id, $this->kitchenUser));

        // Kitchen staff should NOT have access to pending or delivered orders
        $pendingOrder = $this->createTestOrder(Order::STATUS_PENDING);
        $this->assertFalse((bool) $this->resolveOrderChannel($pendingOrder->id, $this->kitchenUser));

        $deliveredOrder = $this->createTestOrder(Order::STATUS_DELIVERED);
        $this->assertFalse((bool) $this->resolveOrderChannel($deliveredOrder->id, $this->kitchenUser));
    }

    public function test_order_channel_rejects_nonexistent_order_id(): void
    {
        $this->assertFalse((bool) $this->resolveOrderChannel(999999, $this->adminUser));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper Methods
    // ─────────────────────────────────────────────────────────────────────────

    private function createTestOrder(string $status): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'Guest Tester',
            'customer_phone' => '0241234567',
            'subtotal' => 75.00,
            'delivery_fee' => 0.00,
            'total' => 75.00,
            'status' => $status,
        ]);

        $order->items()->create([
            'menu_item_id' => $this->menuItem->id,
            'item_name' => $this->menuItem->name,
            'unit_price' => $this->menuItem->price,
            'quantity' => 1,
            'line_total' => 75.00,
        ]);

        return $order;
    }

    private function resolveChannel(string $channelName, User $user): mixed
    {
        $manager = app(\Illuminate\Broadcasting\BroadcastManager::class);
        $broadcaster = $manager->connection();

        $reflection = new \ReflectionObject($broadcaster);
        $channelsProp = $reflection->getProperty('channels');
        $channelsProp->setAccessible(true);
        $channels = $channelsProp->getValue($broadcaster);

        foreach ($channels as $pattern => $callback) {
            if ($pattern === $channelName) {
                if (is_callable($callback)) {
                    return $callback($user);
                }
            }
        }

        return false;
    }

    private function resolveOrderChannel(int $orderId, User $user): mixed
    {
        $manager = app(\Illuminate\Broadcasting\BroadcastManager::class);
        $broadcaster = $manager->connection();

        $reflection = new \ReflectionObject($broadcaster);
        $channelsProp = $reflection->getProperty('channels');
        $channelsProp->setAccessible(true);
        $channels = $channelsProp->getValue($broadcaster);

        foreach ($channels as $pattern => $callback) {
            if ($pattern === 'orders.{orderId}') {
                if (is_callable($callback)) {
                    return $callback($user, $orderId);
                }
            }
        }

        return false;
    }
}
