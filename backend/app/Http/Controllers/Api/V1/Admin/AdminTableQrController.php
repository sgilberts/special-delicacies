<?php
namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantTable;
use App\Models\TableQrCode;
use App\Services\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AdminTableQrController extends Controller
{
    public function __construct(protected QrCodeService $qrCodeService) {}

    public function index(Request $request): JsonResponse
    {
        $query = TableQrCode::with('table');

        if ($search = $request->query('search')) {
            $query->whereHas('table', fn($q) => $q->where('table_number', 'like', "%{$search}%"));
        }

        if ($request->has('is_active')) {
            $isActive = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        $qrCodes = $query->orderByDesc('created_at')->paginate(15);

        // Strip token_hash from response - never expose it
        $items = $qrCodes->getCollection()->map(function ($qr) {
            $qrUrl = $this->resolveActiveQrUrl($qr);

            return [
                'id' => $qr->id,
                'restaurant_table_id' => $qr->restaurant_table_id,
                'is_active' => $qr->is_active,
                'revoked_at' => $qr->revoked_at,
                'created_at' => $qr->created_at,
                'updated_at' => $qr->updated_at,
                'qr_url' => $qrUrl,
                'table' => $qr->table ? [
                    'id' => $qr->table->id,
                    'table_number' => $qr->table->table_number,
                    'capacity' => $qr->table->capacity,
                    'is_active' => $qr->table->is_active,
                ] : null,
            ];
        });

        $total = TableQrCode::count();
        $activeCount = TableQrCode::where('is_active', true)->whereNull('revoked_at')->count();
        $revokedCount = TableQrCode::where('is_active', false)->count();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $qrCodes->currentPage(),
                'last_page' => $qrCodes->lastPage(),
                'per_page' => $qrCodes->perPage(),
                'total' => $qrCodes->total(),
            ],
            'stats' => [
                'total' => $total,
                'active' => $activeCount,
                'revoked' => $revokedCount,
            ],
        ]);
    }

    public function show(int $tableId): JsonResponse
    {
        $table = RestaurantTable::find($tableId);
        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }

        $qrCode = TableQrCode::where('restaurant_table_id', $tableId)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->latest()
            ->first();

        if (!$qrCode) {
            return response()->json(['success' => false, 'message' => 'No active QR code for this table.'], Response::HTTP_NOT_FOUND);
        }

        $cachedUrl = $this->resolveActiveQrUrl($qrCode);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $qrCode->id,
                'restaurant_table_id' => $qrCode->restaurant_table_id,
                'is_active' => $qrCode->is_active,
                'revoked_at' => $qrCode->revoked_at,
                'created_at' => $qrCode->created_at,
                'qr_url' => $cachedUrl,
                'table' => [
                    'id' => $table->id,
                    'table_number' => $table->table_number,
                    'capacity' => $table->capacity,
                    'is_active' => $table->is_active,
                ],
            ],
        ]);
    }

    public function generate(int $tableId): JsonResponse
    {
        $table = RestaurantTable::find($tableId);
        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }

        $result = $this->qrCodeService->generateForTable($table, auth()->user());
        $plainToken = $result['plain_token'];
        $qrCode = $result['qr_code'];

        $frontendUrl = rtrim(config('app.frontend_url', 'https://special-delicacies.local'), '/');
        $qrUrl = "{$frontendUrl}/table/?token={$plainToken}";

        Cache::forever("table_qr_url_{$table->id}", $qrUrl);
        Cache::forever("table_qr_token_{$table->id}", $plainToken);

        return response()->json([
            'success' => true,
            'message' => 'QR code generated successfully.',
            'data' => [
                'token' => $plainToken,
                'qr_url' => $qrUrl,
                'qr_id' => $qrCode->id,
                'table' => [
                    'id' => $table->id,
                    'table_number' => $table->table_number,
                    'capacity' => $table->capacity,
                    'is_active' => $table->is_active,
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    public function regenerate(int $tableId): JsonResponse
    {
        $table = RestaurantTable::find($tableId);
        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }

        $result = $this->qrCodeService->generateForTable($table, auth()->user());
        $plainToken = $result['plain_token'];
        $qrCode = $result['qr_code'];

        $frontendUrl = rtrim(config('app.frontend_url', 'https://special-delicacies.local'), '/');
        $qrUrl = "{$frontendUrl}/table/?token={$plainToken}";

        Cache::forever("table_qr_url_{$table->id}", $qrUrl);
        Cache::forever("table_qr_token_{$table->id}", $plainToken);

        return response()->json([
            'success' => true,
            'message' => 'QR code regenerated successfully. Previous QR code has been revoked.',
            'data' => [
                'token' => $plainToken,
                'qr_url' => $qrUrl,
                'qr_id' => $qrCode->id,
                'table' => [
                    'id' => $table->id,
                    'table_number' => $table->table_number,
                    'capacity' => $table->capacity,
                    'is_active' => $table->is_active,
                ],
            ],
        ]);
    }

    public function revoke(int $tableId): JsonResponse
    {
        $table = RestaurantTable::find($tableId);
        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found.'], Response::HTTP_NOT_FOUND);
        }

        $qrCode = TableQrCode::where('restaurant_table_id', $tableId)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->latest()
            ->first();

        if (!$qrCode) {
            return response()->json(['success' => false, 'message' => 'No active QR code to revoke for this table.'], Response::HTTP_NOT_FOUND);
        }

        $this->qrCodeService->revoke($qrCode);

        Cache::forget("table_qr_url_{$table->id}");
        Cache::forget("table_qr_token_{$table->id}");

        return response()->json(['success' => true, 'message' => 'QR code revoked successfully.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $qrCode = TableQrCode::whereKey($id)->where('is_active', false)->whereNotNull('revoked_at')->first();
        if (! $qrCode) {
            return response()->json(['success' => false, 'message' => 'Only revoked QR code records can be deleted.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $qrCode->delete();
        return response()->json(['success' => true, 'message' => 'Revoked QR code record deleted.']);
    }

    /** Return a canonical QR URL only when its cached token belongs to this active record. */
    private function resolveActiveQrUrl(TableQrCode $qrCode): ?string
    {
        if (! $qrCode->is_active || $qrCode->revoked_at) {
            return null;
        }

        $tokenKey = "table_qr_token_{$qrCode->restaurant_table_id}";
        $token = Cache::get($tokenKey);
        if (! is_string($token) || ! hash_equals($qrCode->token_hash, QrCodeService::hashToken($token))) {
            // Migrate older installations where the raw token was embedded in the cached path URL.
            $cachedUrl = Cache::get("table_qr_url_{$qrCode->restaurant_table_id}");
            if (is_string($cachedUrl)) {
                $parts = parse_url($cachedUrl);
                parse_str($parts['query'] ?? '', $query);
                $token = $query['token'] ?? basename(rtrim($parts['path'] ?? '', '/'));
            }
        }

        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)
            || ! hash_equals($qrCode->token_hash, QrCodeService::hashToken($token))) {
            return null;
        }

        $frontendUrl = rtrim(config('app.frontend_url', 'https://special-delicacies.local'), '/');
        $canonicalUrl = "{$frontendUrl}/table/?token={$token}";
        Cache::forever($tokenKey, $token);
        Cache::forever("table_qr_url_{$qrCode->restaurant_table_id}", $canonicalUrl);

        return $canonicalUrl;
    }
}
