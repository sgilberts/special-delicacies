<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminWebsiteContentController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Get website content settings.
     */
    public function index(): JsonResponse
    {
        $settings = RestaurantSetting::where('group', 'content')
            ->orWhereIn('key', ['restaurant_name', 'restaurant_phone', 'restaurant_email', 'restaurant_address', 'operating_hours', 'restaurant_logo'])
            ->pluck('value', 'key')
            ->all();

        $galleryItems = [];
        if (! empty($settings['gallery_items'])) {
            $decoded = json_decode($settings['gallery_items'], true);
            if (is_array($decoded)) {
                $galleryItems = $decoded;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'hero_title'         => $settings['hero_title'] ?? 'Special Delicacies',
                'hero_subtitle'      => $settings['hero_subtitle'] ?? 'Fine Dining & Takeaway Experience',
                'hero_announcement'  => $settings['hero_announcement'] ?? 'Authentic culinary craftsmanship, served daily',
                'about_story'        => $settings['about_story'] ?? '',
                'about_mission'      => $settings['about_mission'] ?? '',
                'opening_hours_days' => $settings['opening_hours_days'] ?? 'Monday - Sunday: 11:00 AM - 10:00 PM',
                'restaurant_logo'    => $settings['restaurant_logo'] ?? null,
                'favicon_url'        => RestaurantSetting::getValue('favicon_url'),
                'gallery_items'      => $galleryItems,
            ],
        ]);
    }

    /**
     * Update website content settings.
     */
    public function update(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $validated = $request->validate([
            'hero_title'         => ['required', 'string', 'max:255'],
            'hero_subtitle'      => ['required', 'string', 'max:255'],
            'hero_announcement'  => ['nullable', 'string', 'max:500'],
            'about_story'        => ['nullable', 'string', 'max:5000'],
            'about_mission'      => ['nullable', 'string', 'max:5000'],
            'opening_hours_days' => ['nullable', 'string', 'max:255'],
            'gallery_items'      => ['nullable', 'array'],
            'gallery_items.*.id'          => ['nullable', 'numeric'],
            'gallery_items.*.category'    => ['required', 'string', 'in:Food,Restaurant,Events'],
            'gallery_items.*.title'       => ['required', 'string', 'max:150'],
            'gallery_items.*.description' => ['required', 'string', 'max:500'],
            'gallery_items.*.image_url'   => ['nullable', 'string', 'max:500'],
            'gallery_items.*.tint'        => ['nullable', 'string', 'max:50'],
        ]);

        $oldContent = RestaurantSetting::where('group', 'content')->pluck('value', 'key')->all();

        DB::transaction(function () use ($validated, $currentUser, $oldContent, $request) {
            $updates = [
                'hero_title'         => $validated['hero_title'],
                'hero_subtitle'      => $validated['hero_subtitle'],
                'hero_announcement'  => $validated['hero_announcement'] ?? null,
                'about_story'        => $validated['about_story'] ?? null,
                'about_mission'      => $validated['about_mission'] ?? null,
                'opening_hours_days' => $validated['opening_hours_days'] ?? null,
                'gallery_items'      => isset($validated['gallery_items']) ? json_encode($validated['gallery_items']) : null,
            ];

            foreach ($updates as $key => $value) {
                RestaurantSetting::setValue($key, $value, 'content');
            }

            $this->auditService->log(
                $currentUser,
                'website_content.update',
                'Updated public website copy and configuration.',
                null,
                $oldContent,
                $updates,
                $request
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Website content updated successfully.',
            'data'    => $this->index()->getData(true)['data'],
        ]);
    }

    /**
     * Upload an image for the public gallery.
     */
    public function uploadGalleryImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        $file = $request->file('image');
        $filename = 'gallery_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('gallery', $filename, 'public');

        $url = Storage::disk('public')->url($path);

        $this->auditService->log(
            $request->user(),
            'gallery.upload_image',
            "Uploaded gallery image {$filename}.",
            null,
            null,
            ['image_url' => $url],
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'Gallery image uploaded successfully.',
            'data'    => [
                'image_url' => $url,
                'path'      => $path,
            ],
        ]);
    }

    /**
     * Upload restaurant logo.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,jpg,webp,svg', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('branding', $filename, 'public');

        $url = asset('storage/' . $path);

        $oldLogo = RestaurantSetting::getValue('restaurant_logo');
        RestaurantSetting::setValue('restaurant_logo', $url, 'general');

        $this->auditService->log(
            $request->user(),
            'settings.upload_logo',
            'Uploaded new restaurant branding logo.',
            null,
            ['restaurant_logo' => $oldLogo],
            ['restaurant_logo' => $url],
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'Restaurant logo uploaded successfully.',
            'data'    => [
                'logo_url' => $url,
            ],
        ]);
    }

    /** Upload the public website favicon. */
    public function uploadFavicon(Request $request): JsonResponse
    {
        $request->validate([
            'favicon' => ['required', 'file', 'mimes:ico,png,svg', 'max:1024'],
        ]);

        $file = $request->file('favicon');
        $filename = 'favicon_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('branding', $filename, 'public');
        $url = Storage::disk('public')->url($path);

        $oldFavicon = RestaurantSetting::getValue('favicon_url');
        RestaurantSetting::setValue('favicon_url', $url, 'general');
        $this->auditService->log($request->user(), 'settings.upload_favicon', 'Uploaded website favicon.', null,
            ['favicon_url' => $oldFavicon], ['favicon_url' => $url], $request);

        return response()->json(['success' => true, 'message' => 'Favicon uploaded successfully.', 'data' => ['favicon_url' => $url]]);
    }
}
