<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class PublicContentController extends Controller
{
    /** Return public-facing restaurant content and published gallery images. */
    public function index(): JsonResponse
    {
        $settings = RestaurantSetting::query()->pluck('value', 'key')->all();
        $decoded = json_decode((string) ($settings['gallery_items'] ?? '[]'), true);
        $sourceItems = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
        $galleryItems = [];

        foreach ($sourceItems as $position => $item) {
            if (! filter_var($item['is_published'] ?? true, FILTER_VALIDATE_BOOLEAN)) continue;
            $imageUrl = $this->publicImageUrl((string) ($item['image_url'] ?? ''));
            if (! $imageUrl) continue;
            $item['image_url'] = $imageUrl;
            $item['display_order'] = (int) ($item['display_order'] ?? $position);
            $item['is_published'] = true;
            $galleryItems[] = $item;
        }

        usort($galleryItems, fn (array $a, array $b) =>
            [$a['display_order'], (int) ($a['id'] ?? 0)] <=> [$b['display_order'], (int) ($b['id'] ?? 0)]
        );
        $heroItems = array_values(array_filter($galleryItems, fn (array $item) =>
            filter_var($item['show_in_hero'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && filter_var($item['hero_active'] ?? true, FILTER_VALIDATE_BOOLEAN)
        ));
        usort($heroItems, fn (array $a, array $b) =>
            [(int) ($a['hero_sort_order'] ?? $a['display_order']), (int) ($a['id'] ?? 0)]
            <=> [(int) ($b['hero_sort_order'] ?? $b['display_order']), (int) ($b['id'] ?? 0)]
        );

        $publicData = [
            'restaurant_name' => $settings['restaurant_name'] ?? 'Special Delicacies',
            'restaurant_phone' => $settings['restaurant_phone'] ?? '+233 30 123 4567',
            'restaurant_email' => $settings['restaurant_email'] ?? 'contact@special-delicacies.local',
            'restaurant_address' => $settings['restaurant_address'] ?? '100 Gourmet Boulevard, Culinary Quarter',
            'currency' => $settings['currency'] ?? 'GHS',
            'currency_symbol' => $settings['currency_symbol'] ?? 'GH₵',
            'operating_hours' => $settings['operating_hours'] ?? '11:00 - 22:00',
            'opening_hours_days' => $settings['opening_hours_days'] ?? 'Monday - Sunday: 11:00 AM - 10:00 PM',
            'dine_in_enabled' => filter_var($settings['dine_in_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'delivery_enabled' => filter_var($settings['delivery_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'restaurant_logo' => $settings['restaurant_logo'] ?? null,
            'favicon_url' => $settings['favicon_url'] ?? null,
            'hero_title' => $settings['hero_title'] ?? 'Special Delicacies',
            'hero_subtitle' => $settings['hero_subtitle'] ?? 'Fine Dining & Takeaway Experience',
            'hero_announcement' => $settings['hero_announcement'] ?? 'Authentic culinary craftsmanship, served daily',
            'about_story' => $settings['about_story'] ?? 'Special Delicacies was founded with a singular conviction: that exceptional food brings communities together. What began as a humble dream has grown into a destination where guests can delight in authentic, thoughtfully prepared meals made from the freshest ingredients.',
            'about_mission' => $settings['about_mission'] ?? 'Quality Food, Community First, Excellence Always',
            'gallery_items' => $galleryItems,
            'hero_items' => $heroItems,
        ];

        $etag = '"'.hash('sha256', json_encode($publicData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'"';
        $response = response()->json(['success' => true, 'data' => $publicData])
            ->setEtag($etag)
            ->header('Cache-Control', 'public, max-age=0, must-revalidate');
        $response->isNotModified(request());

        return $response;
    }

    private function publicImageUrl(string $imageUrl): ?string
    {
        $path = parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl;
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) $path = substr($path, strlen('storage/'));
        if (! str_starts_with($path, 'gallery/') || str_contains($path, '..')) return null;
        if (! Storage::disk('public')->exists($path)) return null;

        return Storage::disk('public')->url($path);
    }
}
