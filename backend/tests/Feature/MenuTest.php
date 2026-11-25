<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_menu_category_and_items(): void
    {
        $category = MenuCategory::create([
            'name' => 'Main Courses',
            'slug' => 'main-courses',
            'description' => 'Hearty main dishes',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $item = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Signature Braised Beef',
            'slug' => 'signature-braised-beef',
            'description' => 'Slow-cooked tender beef with seasonal herbs',
            'price' => 24.50,
            'image_path' => '/images/beef.jpg',
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $this->assertDatabaseHas('menu_categories', ['slug' => 'main-courses']);
        $this->assertDatabaseHas('menu_items', ['slug' => 'signature-braised-beef']);
        $this->assertEquals('24.50', $item->price);
        $this->assertEquals($category->id, $item->category->id);
        $this->assertCount(1, $category->items);
    }

    public function test_category_deletion_is_restricted_when_items_exist(): void
    {
        $category = MenuCategory::create([
            'name' => 'Beverages',
            'slug' => 'beverages',
            'sort_order' => 2,
        ]);

        MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Sparkling Lemonade',
            'slug' => 'sparkling-lemonade',
            'price' => 5.00,
            'is_available' => true,
        ]);

        $this->expectException(QueryException::class);
        $category->delete();
    }

    public function test_menu_scopes_filter_active_and_available(): void
    {
        $activeCategory = MenuCategory::create([
            'name' => 'Appetizers',
            'slug' => 'appetizers',
            'is_active' => true,
        ]);

        $inactiveCategory = MenuCategory::create([
            'name' => 'Seasonal Specials',
            'slug' => 'seasonal-specials',
            'is_active' => false,
        ]);

        $availableItem = MenuItem::create([
            'category_id' => $activeCategory->id,
            'name' => 'Spring Rolls',
            'slug' => 'spring-rolls',
            'price' => 8.50,
            'is_available' => true,
        ]);

        $unavailableItem = MenuItem::create([
            'category_id' => $activeCategory->id,
            'name' => 'Truffle Fries',
            'slug' => 'truffle-fries',
            'price' => 12.00,
            'is_available' => false,
        ]);

        $this->assertCount(1, MenuCategory::active()->get());
        $this->assertCount(1, MenuItem::available()->get());
        $this->assertCount(1, $activeCategory->availableItems);
    }
}
