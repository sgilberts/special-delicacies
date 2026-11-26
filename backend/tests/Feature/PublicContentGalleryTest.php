<?php

namespace Tests\Feature;

use App\Models\RestaurantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicContentGalleryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_content_returns_only_published_existing_gallery_files_and_ordered_hero_items(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('gallery/second.webp', 'second');
        Storage::disk('public')->put('gallery/first.webp', 'first');
        RestaurantSetting::setValue('gallery_items', json_encode([
            ['id' => 2, 'title' => 'Second', 'description' => 'Second image', 'category' => 'Food', 'image_url' => '/storage/gallery/second.webp', 'display_order' => 1, 'is_published' => true, 'show_in_hero' => true, 'hero_active' => true, 'hero_sort_order' => 2],
            ['id' => 1, 'title' => 'First', 'description' => 'First image', 'category' => 'Food', 'image_url' => '/storage/gallery/first.webp', 'display_order' => 0, 'is_published' => true, 'show_in_hero' => true, 'hero_active' => true, 'hero_sort_order' => 1],
            ['id' => 3, 'title' => 'Draft', 'description' => 'Hidden', 'category' => 'Food', 'image_url' => '/storage/gallery/first.webp', 'is_published' => false, 'show_in_hero' => true],
            ['id' => 4, 'title' => 'Broken', 'description' => 'Missing', 'category' => 'Food', 'image_url' => '/storage/gallery/missing.webp', 'is_published' => true, 'show_in_hero' => true],
            ['id' => 5, 'title' => 'External', 'description' => 'Not local media', 'category' => 'Food', 'image_url' => 'https://example.test/image.webp', 'is_published' => true, 'show_in_hero' => true],
        ]), 'content');

        $response = $this->getJson('/api/v1/content')->assertOk();
        $response->assertJsonPath('data.gallery_items.0.title', 'First')
            ->assertJsonPath('data.gallery_items.1.title', 'Second')
            ->assertJsonPath('data.hero_items.0.title', 'First')
            ->assertJsonPath('data.hero_items.1.title', 'Second');
        $this->assertCount(2, $response->json('data.gallery_items'));
        $this->assertCount(2, $response->json('data.hero_items'));
    }

    public function test_public_content_uses_etag_to_return_not_modified_for_unchanged_gallery(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('gallery/current.webp', 'current');
        RestaurantSetting::setValue('gallery_items', json_encode([
            ['id' => 1, 'title' => 'Current', 'description' => 'Caption', 'category' => 'Food', 'image_url' => '/storage/gallery/current.webp', 'is_published' => true],
        ]), 'content');

        $first = $this->getJson('/api/v1/content')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeader('If-None-Match', $etag)->get('/api/v1/content')->assertStatus(304);
    }
}
