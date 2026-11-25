<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeliveryZoneController extends Controller
{
    public function __construct(
        protected ?AuditService $auditService = null
    ) {
        $this->auditService = $auditService ?? app(AuditService::class);
    }
    /**
     * List all delivery zones (admin — includes inactive).
     */
    public function index(): JsonResponse
    {
        $zones = DeliveryZone::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data'    => $zones,
        ]);
    }

    /**
     * Store a new delivery zone.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:100|unique:delivery_zones,name',
            'description'  => 'nullable|string|max:500',
            'delivery_fee' => 'required|numeric|min:0|max:9999.99',
            'is_active'    => 'boolean',
        ]);

        $zone = DeliveryZone::create([
            'name'         => $validated['name'],
            'description'  => $validated['description'] ?? null,
            'delivery_fee' => $validated['delivery_fee'],
            'is_active'    => $validated['is_active'] ?? true,
        ]);

        $this->auditService->log(
            $request->user(),
            'delivery_zone.create',
            "Created delivery zone '{$zone->name}' with fee GH₵ {$zone->delivery_fee}.",
            $zone,
            null,
            $zone->toArray(),
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'Delivery zone created successfully.',
            'data'    => $zone,
        ], 201);
    }

    /**
     * Show a single delivery zone.
     */
    public function show(int $id): JsonResponse
    {
        $zone = DeliveryZone::find($id);

        if (! $zone) {
            return response()->json(['success' => false, 'message' => 'Delivery zone not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $zone]);
    }

    /**
     * Update an existing delivery zone.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $zone = DeliveryZone::find($id);

        if (! $zone) {
            return response()->json(['success' => false, 'message' => 'Delivery zone not found.'], 404);
        }

        $validated = $request->validate([
            'name'         => 'sometimes|string|max:100|unique:delivery_zones,name,' . $zone->id,
            'description'  => 'nullable|string|max:500',
            'delivery_fee' => 'sometimes|numeric|min:0|max:9999.99',
            'is_active'    => 'sometimes|boolean',
        ]);

        $oldValues = $zone->toArray();
        $zone->update($validated);
        $newValues = $zone->fresh()->toArray();

        $this->auditService->log(
            $request->user(),
            'delivery_zone.update',
            "Updated delivery zone '{$zone->name}'.",
            $zone,
            $oldValues,
            $newValues,
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'Delivery zone updated successfully.',
            'data'    => $zone->fresh(),
        ]);
    }

    /**
     * Toggle the active status of a delivery zone.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $zone = DeliveryZone::find($id);

        if (! $zone) {
            return response()->json(['success' => false, 'message' => 'Delivery zone not found.'], 404);
        }

        $oldStatus = $zone->is_active;
        $newStatus = ! $oldStatus;
        $zone->update(['is_active' => $newStatus]);

        $this->auditService->log(
            request()->user(),
            'delivery_zone.toggle_status',
            sprintf("Delivery zone '%s' was %s.", $zone->name, $newStatus ? 'activated' : 'deactivated'),
            $zone,
            ['is_active' => $oldStatus],
            ['is_active' => $newStatus],
            request()
        );

        return response()->json([
            'success' => true,
            'message' => 'Delivery zone status updated.',
            'data'    => $zone->fresh(),
        ]);
    }

    /**
     * Delete a delivery zone (only if no orders reference it).
     */
    public function destroy(int $id): JsonResponse
    {
        $zone = DeliveryZone::find($id);

        if (! $zone) {
            return response()->json(['success' => false, 'message' => 'Delivery zone not found.'], 404);
        }

        if ($zone->orders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a delivery zone that has associated orders.',
            ], 422);
        }

        $deletedData = $zone->toArray();
        $zone->delete();

        $this->auditService->log(
            request()->user(),
            'delivery_zone.delete',
            "Deleted delivery zone '{$deletedData['name']}'.",
            null,
            $deletedData,
            null,
            request()
        );

        return response()->json([
            'success' => true,
            'message' => 'Delivery zone deleted successfully.',
        ]);
    }
}
