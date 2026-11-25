<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RestaurantSettingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMenuItemTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private MenuCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(RestaurantSettingSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('slug', 'super-admin')->first());

        $this->category = MenuCategory::create([
            'name' => 'Main Courses',
            'slug' => 'main-courses',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_list_and_paginate_menu_items(): void
    {
        Sanctum::actingAs($this->admin);

        for ($i = 1; $i <= 20; $i++) {
            MenuItem::create([
                'category_id' => $this->category->id,
                'name' => "Dish {$i}",
                'slug' => "dish-{$i}",
                'price' => 20.00 + $i,
                'is_available' => true,
                'sort_order' => $i,
            ]);
        }

        $response = $this->getJson('/api/v1/admin/menu/items?per_page=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_admin_can_filter_items_by_category_and_availability(): void
    {
        Sanctum::actingAs($this->admin);

        $drinksCat = MenuCategory::create(['name' => 'Drinks', 'slug' => 'drinks']);

        MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Available Food',
            'slug' => 'available-food',
            'price' => 30.00,
            'is_available' => true,
        ]);

        MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Sold Out Food',
            'slug' => 'sold-out-food',
            'price' => 25.00,
            'is_available' => false,
        ]);

        MenuItem::create([
            'category_id' => $drinksCat->id,
            'name' => 'Cocktail',
            'slug' => 'cocktail',
            'price' => 15.00,
            'is_available' => true,
        ]);

        // Filter by availability
        $availResp = $this->getJson('/api/v1/admin/menu/items?is_available=0');
        $availResp->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sold Out Food');

        // Filter by category
        $catResp = $this->getJson("/api/v1/admin/menu/items?category_id={$drinksCat->id}");
        $catResp->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cocktail');
    }

    public function test_admin_can_create_menu_item_with_image(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $image = UploadedFile::fake()->image('tilapia.jpg', 600, 600);

        $response = $this->postJson('/api/v1/admin/menu/items', [
            'category_id' => $this->category->id,
            'name' => 'Grilled Tilapia with Banku',
            'description' => 'Fresh whole tilapia seasoned with traditional herbs and hot pepper sauce.',
            'price' => '65.50',
            'is_available' => true,
            'sort_order' => 1,
            'image' => $image,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Grilled Tilapia with Banku')
            ->assertJsonPath('data.price', '65.50');

        $item = MenuItem::where('slug', 'grilled-tilapia-with-banku')->first();
        $this->assertNotNull($item);
        $this->assertNotNull($item->getRawOriginal('image_path'));
        Storage::disk('public')->assertExists($item->getRawOriginal('image_path'));
    }

    public function test_menu_item_creation_rejects_invalid_price(): void
    {
        Sanctum::actingAs($this->admin);

        // Negative price
        $response1 = $this->postJson('/api/v1/admin/menu/items', [
            'category_id' => $this->category->id,
            'name' => 'Invalid Negative Item',
            'price' => -10.00,
        ]);
        $response1->assertStatus(422)
            ->assertJsonValidationErrors(['price']);

        // Non-numeric price
        $response2 = $this->postJson('/api/v1/admin/menu/items', [
            'category_id' => $this->category->id,
            'name' => 'Invalid String Price Item',
            'price' => 'free',
        ]);
        $response2->assertStatus(422)
            ->assertJsonValidationErrors(['price']);
    }

    public function test_menu_item_creation_rejects_invalid_image(): void
    {
        Sanctum::actingAs($this->admin);

        // Invalid file format (text file disguised as image)
        $invalidFile = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->postJson('/api/v1/admin/menu/items', [
            'category_id' => $this->category->id,
            'name' => 'Item with Invalid Image',
            'price' => 20.00,
            'image' => $invalidFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_menu_item_creation_rejects_oversized_image(): void
    {
        Sanctum::actingAs($this->admin);

        // Image > 5120 KB
        $oversizedImage = UploadedFile::fake()->image('huge.jpg')->size(6000);

        $response = $this->postJson('/api/v1/admin/menu/items', [
            'category_id' => $this->category->id,
            'name' => 'Item with Oversized Image',
            'price' => 20.00,
            'image' => $oversizedImage,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_admin_can_update_menu_item_price_and_category(): void
    {
        Sanctum::actingAs($this->admin);

        $newCategory = MenuCategory::create(['name' => 'Specials', 'slug' => 'specials']);

        $item = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Beef Shawarma',
            'slug' => 'beef-shawarma',
            'price' => 35.00,
            'is_available' => true,
        ]);

        $response = $this->putJson("/api/v1/admin/menu/items/{$item->id}", [
            'category_id' => $newCategory->id,
            'price' => '42.00',
            'name' => 'Jumbo Beef Shawarma',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.price', '42.00')
            ->assertJsonPath('data.category_id', $newCategory->id)
            ->assertJsonPath('data.name', 'Jumbo Beef Shawarma');

        $this->assertDatabaseHas('menu_items', [
            'id' => $item->id,
            'category_id' => $newCategory->id,
            'price' => 42.00,
        ]);
    }

    public function test_admin_can_toggle_item_availability(): void
    {
        Sanctum::actingAs($this->admin);

        $item = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Fresh Lobster',
            'slug' => 'fresh-lobster',
            'price' => 120.00,
            'is_available' => true,
        ]);

        $response = $this->patchJson("/api/v1/admin/menu/items/{$item->id}/availability", [
            'is_available' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_available', false);

        $this->assertDatabaseHas('menu_items', [
            'id' => $item->id,
            'is_available' => false,
        ]);
    }

    public function test_admin_can_replace_and_remove_item_photograph(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $initialImage = UploadedFile::fake()->image('food1.jpg');
        $item = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Fried Calamari',
            'slug' => 'fried-calamari',
            'price' => 50.00,
            'image_path' => $initialImage->store('menu-items', 'public'),
            'is_available' => true,
        ]);

        $initialPath = $item->getRawOriginal('image_path');
        Storage::disk('public')->assertExists($initialPath);

        // Replace with new image
        $newImage = UploadedFile::fake()->image('food2.png');
        $updateResp = $this->postJson("/api/v1/admin/menu/items/{$item->id}", [
            'image' => $newImage,
        ]);

        $updateResp->assertOk();
        $item->refresh();

        $newPath = $item->getRawOriginal('image_path');
        $this->assertNotEquals($initialPath, $newPath);
        Storage::disk('public')->assertMissing($initialPath);
        Storage::disk('public')->assertExists($newPath);

        // Remove image
        $removeResp = $this->deleteJson("/api/v1/admin/menu/items/{$item->id}/image");
        $removeResp->assertOk();

        $item->refresh();
        $this->assertNull($item->getRawOriginal('image_path'));
        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_admin_can_delete_menu_item_and_cleans_up_image(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $image = UploadedFile::fake()->image('to_delete.jpg');
        $item = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Discontinued Dessert',
            'slug' => 'discontinued-dessert',
            'price' => 18.00,
            'image_path' => $image->store('menu-items', 'public'),
            'is_available' => true,
        ]);

        $rawPath = $item->getRawOriginal('image_path');
        Storage::disk('public')->assertExists($rawPath);

        $response = $this->deleteJson("/api/v1/admin/menu/items/{$item->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('menu_items', ['id' => $item->id]);
        Storage::disk('public')->assertMissing($rawPath);
    }

    public function test_menu_overview_statistics_returns_accurate_counts(): void
    {
        Sanctum::actingAs($this->admin);

        MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Item 1',
            'slug' => 'item-1',
            'price' => 10.00,
            'is_available' => true,
        ]);

        MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Item 2',
            'slug' => 'item-2',
            'price' => 20.00,
            'is_available' => false,
        ]);

        $response = $this->getJson('/api/v1/admin/menu/overview');

        $response->assertOk()
            ->assertJsonPath('data.total_categories', 1)
            ->assertJsonPath('data.active_categories', 1)
            ->assertJsonPath('data.total_items', 2)
            ->assertJsonPath('data.available_items', 1)
            ->assertJsonPath('data.unavailable_items', 1);
    }
}
