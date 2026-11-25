<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use App\Services\QrCodeService;
use Database\Seeders\RestaurantSettingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityAndApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $waiter;
    private User $kitchen;
    private User $delivery;
    private MenuItem $menuItem;
    private RestaurantTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(RestaurantSettingSeeder::class);

        // Set up staff users
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('slug', 'super-admin')->first());

        $this->waiter = User::factory()->create();
        $this->waiter->roles()->attach(Role::where('slug', 'waiter-waitress')->first());

        $this->kitchen = User::factory()->create();
        $this->kitchen->roles()->attach(Role::where('slug', 'kitchen-staff')->first());

        $this->delivery = User::factory()->create();
        $this->delivery->roles()->attach(Role::where('slug', 'delivery-staff')->first());

        // Set up menu item & table
        $category = MenuCategory::create(['name' => 'Appetizers', 'slug' => 'appetizers']);
        $this->menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Garlic Bread',
            'slug' => 'garlic-bread',
            'price' => 6.50,
            'is_available' => true,
        ]);

        $this->table = RestaurantTable::create([
            'table_number' => 'T-01',
            'capacity' => 4,
            'is_active' => true,
        ]);
    }

    public function test_health_endpoint_remains_functional(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Special Delicacies API is running.',
                'application' => 'Special Delicacies',
                'version' => '1.0.0',
            ]);
    }

    public function test_unauthenticated_requests_to_protected_endpoints_are_rejected(): void
    {
        $this->getJson('/api/v1/user')->assertStatus(401);
        $this->getJson('/api/v1/admin/settings')->assertStatus(401);
        $this->putJson('/api/v1/admin/settings', ['settings' => []])->assertStatus(401);
        $this->putJson("/api/v1/admin/menu/items/{$this->menuItem->id}/price", ['price' => 7.00])->assertStatus(401);
        $this->getJson('/api/v1/orders/1')->assertStatus(401);
    }

    public function test_waiter_cannot_edit_menu_prices(): void
    {
        Sanctum::actingAs($this->waiter);

        $response = $this->putJson("/api/v1/admin/menu/items/{$this->menuItem->id}/price", [
            'price' => 9.99,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Forbidden. You do not have permission to perform this action.',
            ]);

        $this->assertEquals('6.50', $this->menuItem->fresh()->price);
    }

    public function test_waiter_cannot_access_administrative_settings(): void
    {
        Sanctum::actingAs($this->waiter);

        $response = $this->getJson('/api/v1/admin/settings');
        $response->assertStatus(403);

        $putResponse = $this->putJson('/api/v1/admin/settings', ['settings' => []]);
        $putResponse->assertStatus(403);
    }

    public function test_kitchen_staff_cannot_access_administrative_settings(): void
    {
        Sanctum::actingAs($this->kitchen);

        $response = $this->getJson('/api/v1/admin/settings');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Forbidden. You do not have permission to perform this action.',
            ]);

        $putResponse = $this->putJson('/api/v1/admin/settings', ['settings' => []]);
        $putResponse->assertStatus(403);
    }

    public function test_delivery_staff_cannot_access_administrative_settings_or_prices(): void
    {
        Sanctum::actingAs($this->delivery);

        $this->getJson('/api/v1/admin/settings')->assertStatus(403);
        $this->putJson("/api/v1/admin/menu/items/{$this->menuItem->id}/price", ['price' => 15.00])->assertStatus(403);
    }

    public function test_administrator_can_edit_price_and_access_settings(): void
    {
        Sanctum::actingAs($this->admin);

        // Test price update
        $priceResponse = $this->putJson("/api/v1/admin/menu/items/{$this->menuItem->id}/price", [
            'price' => 7.50,
        ]);
        $priceResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
        $this->assertEquals('7.50', $this->menuItem->fresh()->price);

        // Test settings access
        $settingsResponse = $this->getJson('/api/v1/admin/settings');
        $settingsResponse->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_deactivated_staff_is_blocked_from_protected_endpoints(): void
    {
        $this->waiter->update(['is_active' => false]);
        Sanctum::actingAs($this->waiter);

        $response = $this->getJson('/api/v1/orders/1');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Your account is deactivated.',
            ]);
    }

    public function test_order_creation_ignores_client_submitted_price(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $this->table->id,
            'customer_name' => 'John Doe',
            'customer_phone' => '123-456-7890',
            'items' => [
                [
                    'menu_item_id' => $this->menuItem->id,
                    'quantity' => 2,
                    'price' => 0.50, // Maliciously low client price
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'subtotal' => '13.00', // 6.50 * 2
                    'total' => '13.00',
                ],
            ]);
    }

    public function test_table_qr_resolution_api(): void
    {
        $qrService = new QrCodeService();
        $generated = $qrService->generateForTable($this->table, $this->admin);

        // Valid token resolves
        $response = $this->getJson("/api/v1/table-lookup/{$generated['plain_token']}");
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'table_number' => 'T-01',
                    'capacity' => 4,
                ],
            ]);

        // Invalid token returns 404
        $badResponse = $this->getJson('/api/v1/table-lookup/0000000000000000000000000000000000000000000000000000000000000000');
        $badResponse->assertStatus(404);
    }
}
