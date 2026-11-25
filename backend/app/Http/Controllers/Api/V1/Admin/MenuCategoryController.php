<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryStatusRequest;
use App\Http\Requests\Admin\UpdateMenuCategoryRequest;
use App\Models\MenuCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MenuCategoryController extends Controller
{
    /**
     * Display a listing of categories with item counts and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MenuCategory::query()->withCount('items');

        // Optional search filter
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Optional active status filter
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $categories = $query->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Store a newly created menu category.
     */
    public function store(StoreMenuCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Safely generate slug if not explicitly provided
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug($data['name']);
        } else {
            $data['slug'] = Str::slug($data['slug']);
        }

        if (! isset($data['sort_order'])) {
            $maxOrder = MenuCategory::max('sort_order');
            $data['sort_order'] = is_null($maxOrder) ? 0 : $maxOrder + 1;
        }

        if (! isset($data['is_active'])) {
            $data['is_active'] = true;
        }

        $category = MenuCategory::create($data);
        $category->loadCount('items');

        return response()->json([
            'success' => true,
            'message' => "Category '{$category->name}' created successfully.",
            'data' => $category,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified menu category.
     */
    public function show(int $id): JsonResponse
    {
        $category = MenuCategory::withCount('items')->with('items')->find($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Menu category not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'data' => $category,
        ]);
    }

    /**
     * Update the specified menu category.
     */
    public function update(int $id, UpdateMenuCategoryRequest $request): JsonResponse
    {
        $category = MenuCategory::find($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Menu category not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = $request->validated();

        if (isset($data['slug']) && ! empty($data['slug'])) {
            $data['slug'] = Str::slug($data['slug']);
        }

        $category->update($data);
        $category->loadCount('items');

        return response()->json([
            'success' => true,
            'message' => "Category '{$category->name}' updated successfully.",
            'data' => $category,
        ]);
    }

    /**
     * Toggle active/inactive status of the menu category.
     */
    public function toggleStatus(int $id, UpdateCategoryStatusRequest $request): JsonResponse
    {
        $category = MenuCategory::find($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Menu category not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $category->update([
            'is_active' => $request->validated('is_active'),
        ]);

        $category->loadCount('items');

        $statusText = $category->is_active ? 'activated' : 'deactivated';

        return response()->json([
            'success' => true,
            'message' => "Category '{$category->name}' has been {$statusText}.",
            'data' => $category,
        ]);
    }

    /**
     * Safely remove the specified menu category.
     * Prevents deletion if category contains menu items.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = MenuCategory::withCount('items')->find($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Menu category not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($category->items_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete category '{$category->name}' because it contains {$category->items_count} associated menu item(s). Reassign or delete those items first.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $categoryName = $category->name;
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => "Category '{$categoryName}' deleted successfully.",
        ]);
    }

    /**
     * Generate unique slug for a category.
     */
    private function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 2;

        while (MenuCategory::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
