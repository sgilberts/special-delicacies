<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;

class MenuOverviewController extends Controller
{
    /**
     * Display a statistical summary of menu categories and items.
     */
    public function index(): JsonResponse
    {
        $totalCategories = MenuCategory::count();
        $activeCategories = MenuCategory::where('is_active', true)->count();
        $inactiveCategories = MenuCategory::where('is_active', false)->count();

        $totalItems = MenuItem::count();
        $availableItems = MenuItem::where('is_available', true)->count();
        $unavailableItems = MenuItem::where('is_available', false)->count();

        $recentItems = MenuItem::with('category:id,name,slug')
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_categories' => $totalCategories,
                'active_categories' => $activeCategories,
                'inactive_categories' => $inactiveCategories,
                'total_items' => $totalItems,
                'available_items' => $availableItems,
                'unavailable_items' => $unavailableItems,
                'recent_items' => $recentItems,
            ],
        ]);
    }
}
