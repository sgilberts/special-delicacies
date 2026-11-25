<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuItemRequest;
use App\Http\Requests\Admin\UpdateItemAvailabilityRequest;
use App\Http\Requests\Admin\UpdateMenuItemRequest;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MenuItemController extends Controller
{
    /**
     * Display a paginated listing of menu items with filtering and search.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MenuItem::query()->with('category:id,name,slug,is_active');

        // Filter by category
        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }

        // Filter by availability
        if ($request->has('is_available')) {
            $query->where('is_available', filter_var($request->query('is_available'), FILTER_VALIDATE_BOOLEAN));
        }

        // Search in name or description
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Ordering
        $sortBy = $request->query('sort_by', 'sort_order');
        $sortDir = strtolower($request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($sortBy, ['name', 'price', 'sort_order', 'created_at', 'is_available'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('sort_order', 'asc');
        }

        $query->orderBy('name', 'asc');

        $perPage = max(1, min((int) $request->query('per_page', 15), 100));
        $items = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Store a newly created menu item.
     */
    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Generate unique slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug($data['name']);
        } else {
            $data['slug'] = Str::slug($data['slug']);
        }

        // Default sort order
        if (! isset($data['sort_order'])) {
            $maxOrder = MenuItem::where('category_id', $data['category_id'])->max('sort_order');
            $data['sort_order'] = is_null($maxOrder) ? 0 : $maxOrder + 1;
        }

        if (! isset($data['is_available'])) {
            $data['is_available'] = true;
        }

        // Handle image upload securely
        if ($request->hasFile('image')) {
            $imageFile = $request->file('image');
            $filename = Str::random(40) . '.' . ($imageFile->guessExtension() ?? 'jpg');
            $path = $imageFile->storeAs('menu-items', $filename, 'public');
            $data['image_path'] = $path;
        }

        unset($data['image']);

        $item = MenuItem::create($data);
        $item->load('category:id,name,slug');

        return response()->json([
            'success' => true,
            'message' => "Menu item '{$item->name}' created successfully.",
            'data' => $item,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified menu item.
     */
    public function show(int $id): JsonResponse
    {
        $item = MenuItem::with('category')->find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Menu item not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'data' => $item,
        ]);
    }

    /**
     * Update the specified menu item.
     */
    public function update(int $id, UpdateMenuItemRequest $request): JsonResponse
    {
        $item = MenuItem::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Menu item not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = $request->validated();

        if (isset($data['slug']) && ! empty($data['slug'])) {
            $data['slug'] = Str::slug($data['slug']);
        }

        // Secure image upload and replacement
        if ($request->hasFile('image')) {
            $imageFile = $request->file('image');
            $filename = Str::random(40) . '.' . ($imageFile->guessExtension() ?? 'jpg');
            $newPath = $imageFile->storeAs('menu-items', $filename, 'public');

            // Remove old image only after successful new upload
            $oldPath = $item->getRawOriginal('image_path');
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            $data['image_path'] = $newPath;
        }

        unset($data['image']);

        $item->update($data);
        $item->load('category:id,name,slug');

        return response()->json([
            'success' => true,
            'message' => "Menu item '{$item->name}' updated successfully.",
            'data' => $item,
        ]);
    }

    /**
     * Toggle availability status of the menu item.
     */
    public function toggleAvailability(int $id, UpdateItemAvailabilityRequest $request): JsonResponse
    {
        $item = MenuItem::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Menu item not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $item->update([
            'is_available' => $request->validated('is_available'),
        ]);

        $item->load('category:id,name,slug');

        $statusText = $item->is_available ? 'marked as available' : 'marked as unavailable';

        return response()->json([
            'success' => true,
            'message' => "Menu item '{$item->name}' is now {$statusText}.",
            'data' => $item,
        ]);
    }

    /**
     * Remove the photograph of the menu item.
     */
    public function removeImage(int $id): JsonResponse
    {
        $item = MenuItem::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Menu item not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $oldPath = $item->getRawOriginal('image_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $item->update(['image_path' => null]);
        $item->load('category:id,name,slug');

        return response()->json([
            'success' => true,
            'message' => "Photograph removed from '{$item->name}'.",
            'data' => $item,
        ]);
    }

    /**
     * Safely delete the specified menu item.
     */
    public function destroy(int $id): JsonResponse
    {
        $item = MenuItem::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Menu item not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        // Clean up photograph from storage
        $oldPath = $item->getRawOriginal('image_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $itemName = $item->name;
        $item->delete();

        return response()->json([
            'success' => true,
            'message' => "Menu item '{$itemName}' deleted successfully.",
        ]);
    }

    /**
     * Generate unique slug for a menu item.
     */
    private function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 2;

        while (MenuItem::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
