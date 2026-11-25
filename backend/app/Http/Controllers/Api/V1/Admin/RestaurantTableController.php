<?php
namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantTableRequest;
use App\Http\Requests\Admin\UpdateRestaurantTableRequest;
use App\Models\RestaurantTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestaurantTableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RestaurantTable::query()->with('activeQrCode');

        if ($search = $request->query('search')) {
            $query->where('table_number', 'like', "%{$search}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
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
        $table->has_active_qr = false;
        return response()->json(['success' => true, 'data' => $table, 'message' => 'Table created successfully.'], Response::HTTP_CREATED);
    }

    public function show(int $id): JsonResponse
    {
        $table = RestaurantTable::with(['activeQrCode', 'qrCodes'])->find($id);
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
        $table->update($request->validated());
        $table->refresh();
        $table->load('activeQrCode');
        $table->has_active_qr = $table->activeQrCode !== null;
        return response()->json(['success' => true, 'data' => $table, 'message' => 'Table updated successfully.']);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $table = RestaurantTable::find($id);
        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }
        $table->update(['is_active' => !$table->is_active]);
        $table->refresh();
        $status = $table->is_active ? 'activated' : 'deactivated';
        return response()->json(['success' => true, 'data' => $table, 'message' => "Table {$status} successfully."]);
    }

    public function destroy(int $id): JsonResponse
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

        $table->delete();
        return response()->json(['success' => true, 'message' => 'Table deleted successfully.']);
    }
}
