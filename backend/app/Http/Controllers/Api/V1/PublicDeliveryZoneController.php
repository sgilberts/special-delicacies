<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use Illuminate\Http\JsonResponse;

class PublicDeliveryZoneController extends Controller
{
    /**
     * List active delivery zones for public customer ordering.
     */
    public function index(): JsonResponse
    {
        $zones = DeliveryZone::active()
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'delivery_fee', 'is_active']);

        return response()->json([
            'success' => true,
            'data'    => $zones,
        ]);
    }
}
