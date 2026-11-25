<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMenuPriceRequest;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;

class MenuPriceController extends Controller
{
    /**
     * Update the price of a menu item.
     * Enforces the 'menu.price.edit' permission on the backend.
     */
    public function update(int $id, UpdateMenuPriceRequest $request): JsonResponse
    {
        $item = MenuItem::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Menu item not found.',
            ], 404);
        }

        $item->update([
            'price' => $request->validated('price'),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Price for '{$item->name}' updated to {$item->price}.",
            'data' => $item,
        ]);
    }
}
