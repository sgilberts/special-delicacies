<?php
namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantTableRequest;
use App\Http\Requests\Admin\UpdateRestaurantTableRequest;
use App\Models\RestaurantTable;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestaurantTableController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = RestaurantTable::query()->with(['activeQrCode', 'venue:id,name,code']);

        if ($search = $request->query('search')) {
            $query->where('table_number', 'like', "%{$search}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('venue_id')) {
            $query->where('venue_id', $request->query('venue_id'));
        }

        if ($request->has('reservation_eligible')) {
            $query->where('reservation_eligible', filter_var($request->query('reservation_eligible'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('operational_status')) {
            $query->where('operational_status', $request->query('operational_status'));
        }

        $total = RestaurantTable::count();
        $active = RestaurantTable::where('is_active', true)->count();
        $inactive = RestaurantTable::where('is_active', false)->count();
        // Count tables that have at least one active QR code
        $withQr = RestaurantTable::whereHas('qrCodes', fn($q) => $q->where('is_active', true)->whereNull('revoked_at'))->count();

        $tables = $query->orderBy('table_number')->paginate(15);

        // Add has_active_qr computed field
        $tables->getCollection()->transform(function ($table) {
            $table->has_active_qr = $table->activeQrCode !== null;
            return $table;
        });

        return response()->json([
            'success' => true,
            'data' => $tables->items(),
            'meta' => [
                'current_page' => $tables->currentPage(),
                'last_page' => $tables->lastPage(),
                'per_page' => $tables->perPage(),
                'total' => $tables->total(),
            ],
            'stats' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'with_qr' => $withQr,
            ],
        ]);
    }

    public function store(StoreRestaurantTableRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;
        $table = RestaurantTable::create($data);
        $table->refresh();
        $table->load('venue:id,name,code');
        $table->has_active_qr = false;

        $this->auditService->log(
            $request->user(),
            'table.created',
            "Created table {$table->table_number}.",
            $table,
            null,
            $table->only(['table_number', 'capacity', 'venue_id', 'table_type', 'is_active', 'operational_status', 'reservation_eligible']),
            $request
        );

        return response()->json(['success' => true, 'data' => $table, 'message' => 'Table created successfully.'], Response::HTTP_CREATED);
    }

    public function show(int $id): JsonResponse
    {
        $table = RestaurantTable::with(['activeQrCode', 'qrCodes', 'venue:id,name,code', 'unavailabilityPeriods'])->find($id);
        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }
        $table->has_active_qr = $table->activeQrCode !== null;
        return response()->json(['success' => true, 'data' => $table]);
    }

    public function update(UpdateRestaurantTableRequest $request, int $id): JsonResponse
    {
        $table = RestaurantTable::find($id);
        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->validated();

        // D-5B safeguard: a table occupied by a live dine-in order must not be
        // taken out of reservation service until that order reaches a terminal
        // status. Existing `is_active` (QR ordering) semantics are unchanged.
        if (isset($data['operational_status'])
            && $data['operational_status'] !== RestaurantTable::OPERATIONAL_AVAILABLE
            && $table->operational_status === RestaurantTable::OPERATIONAL_AVAILABLE
            && $table->hasActiveDineInOrder()) {
            return response()->json([
                'success' => false,
                'message' => 'This table has an active dine-in order. Complete or cancel the order before taking the table out of service.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $oldValues = $table->only(array_keys($data));
        $table->update($data);
        $table->refresh();
        $table->load(['activeQrCode', 'venue:id,name,code']);
        $table->has_active_qr = $table->activeQrCode !== null;

        $this->auditService->log(
            $request->user(),
            'table.updated',
            "Updated table {$table->table_number}.",
            $table,
            $oldValues,
            $table->only(array_keys($data)),
            $request
        );

        return response()->json(['success' => true, 'data' => $table, 'message' => 'Table updated successfully.']);
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $table = RestaurantTable::find($id);
        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }
        $previous = $table->is_active;
        $table->update(['is_active' => !$table->is_active]);
        $table->refresh();
        $status = $table->is_active ? 'activated' : 'deactivated';

        $this->auditService->log(
            $request->user(),
            'table.status.toggled',
            "Table {$table->table_number} {$status}.",
            $table,
            ['is_active' => $previous],
            ['is_active' => $table->is_active],
            $request
        );

        return response()->json(['success' => true, 'data' => $table, 'message' => "Table {$status} successfully."]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $table = RestaurantTable::find($id);
        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($table->orders()->exists() || $table->qrCodes()->exists()) {
            $reason = $table->orders()->exists() ? 'associated orders' : 'associated QR code records';
            return response()->json([
                'success' => false,
                'message' => "Cannot delete this table because it has {$reason}. Deactivate it instead.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($table->reservationAllocations()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this table because it has reservation allocations. Deactivate it instead.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $snapshot = $table->only(['table_number', 'capacity', 'venue_id']);
        $table->delete();

        $this->auditService->log(
            $request->user(),
            'table.deleted',
            "Deleted table {$snapshot['table_number']}.",
            null,
            $snapshot,
            null,
            $request
        );

        return response()->json(['success' => true, 'message' => 'Table deleted successfully.']);
    }
}
