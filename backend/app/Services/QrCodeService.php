<?php

namespace App\Services;

use App\Models\RestaurantTable;
use App\Models\TableQrCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class QrCodeService
{
    /**
     * Hash a raw QR token using SHA-256.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Generate a cryptographically secure token and active TableQrCode record for a table.
     * Enforces the one-active-QR rule transactionally by revoking any existing active codes.
     *
     * @return array{plain_token: string, qr_code: TableQrCode}
     */
    public function generateForTable(RestaurantTable $table, ?User $generatedBy = null): array
    {
        return DB::transaction(function () use ($table, $generatedBy) {
            // Revoke any existing active QR codes for this table
            TableQrCode::where('restaurant_table_id', $table->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'revoked_at' => now(),
                ]);

            // Generate cryptographically secure 64-character hex token
            $plainToken = bin2hex(random_bytes(32));
            $tokenHash = self::hashToken($plainToken);

            $qrCode = TableQrCode::create([
                'restaurant_table_id' => $table->id,
                'token_hash' => $tokenHash,
                'is_active' => true,
                'generated_by' => $generatedBy?->id,
            ]);

            return [
                'plain_token' => $plainToken,
                'qr_code' => $qrCode,
            ];
        });
    }

    /**
     * Revoke an active QR code.
     */
    public function revoke(TableQrCode $qrCode): bool
    {
        return DB::transaction(function () use ($qrCode) {
            return $qrCode->update([
                'is_active' => false,
                'revoked_at' => now(),
            ]);
        });
    }

    /**
     * Resolve a restaurant table from a raw, client-supplied QR token.
     * Returns null if token is invalid, revoked, or table is inactive.
     */
    public function resolveTableFromToken(string $plainToken): ?RestaurantTable
    {
        if (empty($plainToken) || strlen($plainToken) !== 64) {
            return null;
        }

        $tokenHash = self::hashToken($plainToken);

        $qrCode = TableQrCode::with('table')
            ->where('token_hash', $tokenHash)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->first();

        if (! $qrCode || ! $qrCode->table || ! $qrCode->table->is_active) {
            return null;
        }

        return $qrCode->table;
    }
}
