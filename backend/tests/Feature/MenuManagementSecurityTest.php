<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RestaurantSettingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuManagementSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $waiter;
    private User $kitchen;
    private User $delivery;
    private MenuCategory $category;
    private MenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(RestaurantSettingSeeder::class);

        $this->admin = User::factory()->create([
            'email' => 'admin@test.local',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => true,
        ]);
        $this->admin->roles()->attach(Role::where('slug', 'super-admin')->first());

        $this->waiter = User::factory()->create([
            'email' => 'waiter@test.local',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => true,
        ]);
        $this->waiter->roles()->attach(Role::where('slug', 'waiter-waitress')->first());

        $this->kitchen = User::factory()->create([
            'email' => 'kitchen@test.local',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => true,
        ]);
        $this->kitchen->roles()->attach(Role::where('slug', 'kitchen-staff')->first());

        $this->delivery = User::factory()->create([
            'email' => 'delivery@test.local',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => true,
        ]);
        $this->delivery->roles()->attach(Role::where('slug', 'delivery-staff')->first());

        $this->category = MenuCategory::create([
            'name' => 'Local Dishes',
            'slug' => 'local-dishes',
            'is_active' => true,
        ]);

        $this->menuItem = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Waakye Special',
            'slug' => 'waakye-special',
            'price' => 38.00,
            'is_available' => true,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected_with_401(): void
    {
        $this->getJson('/api/v1/admin/menu/overview')->assertUnauthorized();
        $this->getJson('/api/v1/admin/menu/categories')->assertUnauthorized();
        $this->postJson('/api/v1/admin/menu/categories', ['name' => 'Test'])->assertUnauthorized();
        $this->getJson('/api/v1/admin/menu/items')->assertUnauthorized();
        $this->postJson('/api/v1/admin/menu/items', ['name' => 'Test'])->assertUnauthorized();
        $this->deleteJson("/api/v1/admin/menu/items/{$this->menuItem->id}")->assertUnauthorized();
    }

    public function test_waiter_cannot_create_or_modify_menu_categories_or_items(): void
    {
        Sanctum::actingAs($this->waiter);

        $this->getJson('/api/v1/admin/menu/overview')->assertForbidden();
        $this->postJson('/api/v1/admin/menu/categories', ['name' => 'Hacked Category'])->assertForbidden();
        $this->putJson("/api/v1/admin/menu/categories/{$this->category->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/v1/admin/menu/categories/{$this->category->id}")->assertForbidden();

        $this->postJson('/api/v1/admin/menu/items', ['name' => 'Hacked Item', 'category_id' => $this->category->id, 'price' => 10])->assertForbidden();
        $this->putJson("/api/v1/admin/menu/items/{$this->menuItem->id}", ['price' => 1.00])->assertForbidden();
        $this->deleteJson("/api/v1/admin/menu/items/{$this->menuItem->id}")->assertForbidden();
    }

    public function test_kitchen_staff_cannot_create_or_modify_menu(): void
    {
        Sanctum::actingAs($this->kitchen);

        $this->getJson('/api/v1/admin/menu/overview')->assertForbidden();
        $this->postJson('/api/v1/admin/menu/categories', ['name' => 'Kitchen Category'])->assertForbidden();
        $this->putJson("/api/v1/admin/menu/items/{$this->menuItem->id}", ['price' => 5.00])->assertForbidden();
        $this->deleteJson("/api/v1/admin/menu/items/{$this->menuItem->id}")->assertForbidden();
    }

    public function test_delivery_staff_cannot_create_or_modify_menu(): void
    {
        Sanctum::actingAs($this->delivery);

        $this->getJson('/api/v1/admin/menu/overview')->assertForbidden();
        $this->postJson('/api/v1/admin/menu/categories', ['name' => 'Delivery Category'])->assertForbidden();
        $this->putJson("/api/v1/admin/menu/items/{$this->menuItem->id}", ['price' => 5.00])->assertForbidden();
        $this->deleteJson("/api/v1/admin/menu/items/{$this->menuItem->id}")->assertForbidden();
    }

    public function test_administrator_can_authenticate_via_auth_login(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'SecretPass123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                    'user' => ['id', 'name', 'email', 'roles'],
                ],
            ]);
    }

    public function test_staff_role_can_log_in_to_the_staff_console(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'waiter@test.local',
            'password' => 'SecretPass123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.roles.0', 'waiter-waitress');
    }

    public function test_deactivated_user_cannot_login(): void
    {
        $this->admin->update(['is_active' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'SecretPass123!',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_public_menu_endpoint_returns_only_active_categories_and_available_items(): void
    {
        $inactiveCat = MenuCategory::create([
            'name' => 'Seasonal Specials',
            'slug' => 'seasonal-specials',
            'is_active' => false,
        ]);

        MenuItem::create([
            'category_id' => $inactiveCat->id,
            'name' => 'Inactive Dish',
            'slug' => 'inactive-dish',
            'price' => 50.00,
            'is_available' => true,
        ]);

        $unavailableItem = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Sold Out Soup',
            'slug' => 'sold-out-soup',
            'price' => 30.00,
            'is_available' => false,
        ]);

        $response = $this->getJson('/api/v1/menu');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');

        // Verify inactive category is excluded
        $categoryNames = collect($data)->pluck('name')->all();
        $this->assertContains('Local Dishes', $categoryNames);
        $this->assertNotContains('Seasonal Specials', $categoryNames);

        // Verify unavailable item is excluded from public available_items
        $activeCategory = collect($data)->firstWhere('name', 'Local Dishes');
        $itemNames = collect($activeCategory['available_items'])->pluck('name')->all();
        $this->assertContains('Waakye Special', $itemNames);
        $this->assertNotContains('Sold Out Soup', $itemNames);
    }
}
