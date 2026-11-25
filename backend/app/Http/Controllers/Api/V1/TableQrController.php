<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\QrCodeService;
use Illuminate\Http\JsonResponse;

class TableQrController extends Controller
{
    public function __construct(
        protected QrCodeService $qrCodeService
    ) {}

    /**
     * Resolve a table from a customer's scanned QR token.
     * Does NOT expose database IDs or raw hashes.
     */
    public function resolve(string $token): JsonResponse
    {
        $table = $this->qrCodeService->resolveTableFromToken($token);

        if (! $table) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid, expired, or deactivated table QR code.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'table_id' => $table->id,
                'table_number' => $table->table_number,
                'capacity' => $table->capacity,
            ],
        ]);
    }
}
