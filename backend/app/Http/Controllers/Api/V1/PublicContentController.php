<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSetting;
use Illuminate\Http\JsonResponse;

class PublicContentController extends Controller
{
    /**
     * Get public-facing restaurant content and safe public settings.
     */
    public function index(): JsonResponse
    {
        $settings = RestaurantSetting::all()->pluck('value', 'key')->all();

        // Parse gallery items or return defaults
        $galleryItems = [];
        if (! empty($settings['gallery_items'])) {
            $decoded = json_decode($settings['gallery_items'], true);
            if (is_array($decoded)) {
                $galleryItems = $decoded;
            }
        }

        // Default gallery if none stored
        if (empty($galleryItems)) {
            $galleryItems = [
                [
                    'id' => 1,
                    'category' => 'Food',
                    'title' => 'Signature Gourmet Jollof',
                    'description' => 'Slow-cooked fragrant rice with aromatic spices and tender grilled protein.',
                    'tint' => 'rgba(189, 112, 72, 0.15)',
                    'image_url' => null,
                ],
                [
                    'id' => 2,
                    'category' => 'Restaurant',
                    'title' => 'Dining Room Atmosphere',
                    'description' => 'Intimate and comfortable seating crafted for memorable meals.',
                    'tint' => 'rgba(216, 170, 101, 0.15)',
                    'image_url' => null,
                ],
                [
                    'id' => 3,
                    'category' => 'Events',
                    'title' => 'Private Celebration Setup',
                    'description' => 'Custom table settings and floral arrangements for intimate family gatherings.',
                    'tint' => 'rgba(120, 134, 108, 0.15)',
                    'image_url' => null,
                ],
                [
                    'id' => 4,
                    'category' => 'Food',
                    'title' => 'Artisanal Charcoal Grill',
                    'description' => 'Succulent cuts infused with house-blended seasonings and natural smoke.',
                    'tint' => 'rgba(189, 112, 72, 0.15)',
                    'image_url' => null,
                ],
                [
                    'id' => 5,
                    'category' => 'Restaurant',
                    'title' => 'Open Kitchen Theatre',
                    'description' => 'Watch our master chefs work magic in the bustling culinary station.',
                    'tint' => 'rgba(216, 170, 101, 0.15)',
                    'image_url' => null,
                ],
                [
                    'id' => 6,
                    'category' => 'Events',
                    'title' => 'Catering Showcase',
                    'description' => 'Buffet presentation prepared for corporate banquets and celebrations.',
                    'tint' => 'rgba(120, 134, 108, 0.15)',
                    'image_url' => null,
                ],
            ];
        }

        $publicData = [
            'restaurant_name'     => $settings['restaurant_name'] ?? 'Special Delicacies',
            'restaurant_phone'    => $settings['restaurant_phone'] ?? '+233 30 123 4567',
            'restaurant_email'    => $settings['restaurant_email'] ?? 'contact@special-delicacies.local',
            'restaurant_address'  => $settings['restaurant_address'] ?? '100 Gourmet Boulevard, Culinary Quarter',
            'currency'            => $settings['currency'] ?? 'GHS',
            'currency_symbol'     => $settings['currency_symbol'] ?? 'GH₵',
            'operating_hours'     => $settings['operating_hours'] ?? '11:00 - 22:00',
            'opening_hours_days'  => $settings['opening_hours_days'] ?? 'Monday - Sunday: 11:00 AM - 10:00 PM',
            'dine_in_enabled'     => filter_var($settings['dine_in_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'delivery_enabled'    => filter_var($settings['delivery_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'restaurant_logo'     => $settings['restaurant_logo'] ?? null,
            'favicon_url'         => $settings['favicon_url'] ?? null,

            // Website copy
            'hero_title'          => $settings['hero_title'] ?? 'Special Delicacies',
            'hero_subtitle'       => $settings['hero_subtitle'] ?? 'Fine Dining & Takeaway Experience',
            'hero_announcement'   => $settings['hero_announcement'] ?? 'Authentic culinary craftsmanship, served daily',
            'about_story'         => $settings['about_story'] ?? 'Special Delicacies was founded with a singular conviction: that exceptional food brings communities together. What began as a humble dream has grown into a destination where guests can delight in authentic, thoughtfully prepared meals made from the freshest ingredients.',
            'about_mission'       => $settings['about_mission'] ?? 'Quality Food, Community First, Excellence Always',
            'gallery_items'       => $galleryItems,
        ];

        return response()->json([
            'success' => true,
            'data'    => $publicData,
        ]);
    }
}
