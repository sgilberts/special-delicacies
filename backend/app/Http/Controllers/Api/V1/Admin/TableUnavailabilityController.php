<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantTable;
use App\Models\TableUnavailabilityPeriod;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 5.2 table maintenance / temporary unavailability configuration.
 * These periods are configuration only; the availability engine (Stage 5.3)
 * will consume them.
 */
class TableUnavailabilityController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function index(int $tableId): JsonResponse
    {
        $table = RestaurantTable::find($tableId);
        if (! $table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'data' => $table->unavailabilityPeriods()->get(),
        ]);
    }

    public function store(Request $request, int $tableId): JsonResponse
    {
        $table = RestaurantTable::find($tableId);
        if (! $table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $period = TableUnavailabilityPeriod::create($validated + [
            'restaurant_table_id' => $table->id,
            'created_by' => $request->user()?->id,
        ]);

        $this->auditService->log(
            $request->user(),
            'table.unavailability.created',
            "Scheduled unavailability for table {$table->table_number}.",
            $period,
            null,
            $period->only(['starts_at', 'ends_at', 'reason']),
            $request
        );

        return response()->json([
            'success' => true,
            'data' => $period,
            'message' => 'Table unavailability saved successfully.',
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $tableId, int $periodId): JsonResponse
    {
        $table = RestaurantTable::find($tableId);
        $period = $table?->unavailabilityPeriods()->whereKey($periodId)->first();

        if (! $table || ! $period) {
            return response()->json(['success' => false, 'message' => 'Unavailability period not found.'], Response::HTTP_NOT_FOUND);
        }

        $snapshot = $period->only(['starts_at', 'ends_at', 'reason']);
        $period->delete();

        $this->auditService->log(
            $request->user(),
            'table.unavailability.deleted',
            "Removed unavailability for table {$table->table_number}.",
            null,
            $snapshot,
            null,
            $request
        );

        return response()->json(['success' => true, 'message' => 'Table unavailability removed successfully.']);
    }
}
