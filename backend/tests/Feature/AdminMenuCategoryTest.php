<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RestaurantSettingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMenuCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(RestaurantSettingSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('slug', 'super-admin')->first());
    }

    public function test_admin_can_list_categories_with_item_counts(): void
    {
        Sanctum::actingAs($this->admin);

        $cat1 = MenuCategory::create(['name' => 'Soups', 'slug' => 'soups', 'is_active' => true, 'sort_order' => 1]);
        $cat2 = MenuCategory::create(['name' => 'Beverages', 'slug' => 'beverages', 'is_active' => false, 'sort_order' => 2]);

        MenuItem::create([
            'category_id' => $cat1->id,
            'name' => 'Light Soup',
            'slug' => 'light-soup',
            'price' => 35.00,
            'is_available' => true,
        ]);

        $response = $this->getJson('/api/v1/admin/menu/categories');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Soups')
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonPath('data.1.name', 'Beverages')
            ->assertJsonPath('data.1.items_count', 0);
    }

    public function test_admin_can_filter_categories_by_active_status_and_search(): void
    {
        Sanctum::actingAs($this->admin);

        MenuCategory::create(['name' => 'Local Dishes', 'slug' => 'local-dishes', 'is_active' => true]);
        MenuCategory::create(['name' => 'Continental Dishes', 'slug' => 'continental-dishes', 'is_active' => false]);

        $searchResponse = $this->getJson('/api/v1/admin/menu/categories?search=Local');
        $searchResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Local Dishes');

        $activeResponse = $this->getJson('/api/v1/admin/menu/categories?is_active=1');
        $activeResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Local Dishes');
    }

    public function test_admin_can_create_menu_category(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'name' => 'Grills & Barbecue',
            'description' => 'Charcoal-grilled meats and fish seasoned with local spices.',
            'sort_order' => 3,
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/admin/menu/categories', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Grills & Barbecue')
            ->assertJsonPath('data.slug', 'grills-barbecue');

        $this->assertDatabaseHas('menu_categories', [
            'name' => 'Grills & Barbecue',
            'slug' => 'grills-barbecue',
        ]);
    }

    public function test_category_creation_handles_slug_generation_and_duplicates(): void
    {
        Sanctum::actingAs($this->admin);

        MenuCategory::create(['name' => 'Seafood', 'slug' => 'seafood']);

        $response = $this->postJson('/api/v1/admin/menu/categories', [
            'name' => 'Seafood',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'seafood-2');

        $this->assertDatabaseHas('menu_categories', ['slug' => 'seafood-2']);
    }

    public function test_category_creation_rejects_invalid_data(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/menu/categories', [
            'name' => '', // missing name
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_can_update_category(): void
    {
        Sanctum::actingAs($this->admin);

        $cat = MenuCategory::create(['name' => 'Beverages', 'slug' => 'beverages']);

        $response = $this->putJson("/api/v1/admin/menu/categories/{$cat->id}", [
            'name' => 'Refreshing Beverages',
            'description' => 'Fresh juices, sodas and cocktails.',
            'sort_order' => 5,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Refreshing Beverages');

        $this->assertDatabaseHas('menu_categories', [
            'id' => $cat->id,
            'name' => 'Refreshing Beverages',
        ]);
    }

    public function test_admin_can_toggle_category_status(): void
    {
        Sanctum::actingAs($this->admin);

        $cat = MenuCategory::create(['name' => 'Desserts', 'slug' => 'desserts', 'is_active' => true]);

        $response = $this->patchJson("/api/v1/admin/menu/categories/{$cat->id}/status", [
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('menu_categories', [
            'id' => $cat->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_delete_empty_category(): void
    {
        Sanctum::actingAs($this->admin);

        $cat = MenuCategory::create(['name' => 'Empty Category', 'slug' => 'empty-category']);

        $response = $this->deleteJson("/api/v1/admin/menu/categories/{$cat->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('menu_categories', ['id' => $cat->id]);
    }

    public function test_cannot_delete_category_with_associated_menu_items(): void
    {
        Sanctum::actingAs($this->admin);

        $cat = MenuCategory::create(['name' => 'Rice Dishes', 'slug' => 'rice-dishes']);
        MenuItem::create([
            'category_id' => $cat->id,
            'name' => 'Jollof Rice with Chicken',
            'slug' => 'jollof-rice-with-chicken',
            'price' => 45.00,
            'is_available' => true,
        ]);

        $response = $this->deleteJson("/api/v1/admin/menu/categories/{$cat->id}");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('Cannot delete category', $response->json('message'));
        $this->assertDatabaseHas('menu_categories', ['id' => $cat->id]);
    }
}
