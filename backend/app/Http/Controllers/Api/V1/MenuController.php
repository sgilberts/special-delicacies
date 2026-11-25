<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use Illuminate\Http\JsonResponse;

class MenuController extends Controller
{
    /**
     * Display a listing of active categories with available menu items.
     */
    public function index(): JsonResponse
    {
        $categories = MenuCategory::active()
            ->ordered()
            ->with(['availableItems'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }
}
