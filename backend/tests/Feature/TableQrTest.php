<?php

namespace Tests\Feature;

use App\Models\RestaurantTable;
use App\Models\TableQrCode;
use App\Models\User;
use App\Services\QrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableQrTest extends TestCase
{
    use RefreshDatabase;

    private QrCodeService $qrService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qrService = new QrCodeService();
    }

    public function test_can_create_restaurant_table(): void
    {
        $table = RestaurantTable::create([
            'table_number' => 'T-01',
            'capacity' => 4,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('restaurant_tables', [
            'table_number' => 'T-01',
            'capacity' => 4,
            'is_active' => true,
        ]);
    }

    public function test_qr_generation_stores_only_hash_and_never_plain_token(): void
    {
        $table = RestaurantTable::create(['table_number' => 'T-02', 'capacity' => 2]);
        $staff = User::factory()->create();

        $result = $this->qrService->generateForTable($table, $staff);
        $plainToken = $result['plain_token'];
        $qrCode = $result['qr_code'];

        $this->assertEquals(64, strlen($plainToken));
        $this->assertEquals(64, strlen($qrCode->token_hash));

        // Verify plain token is NOT stored in database
        $this->assertDatabaseMissing('table_qr_codes', ['token_hash' => $plainToken]);

        // Verify hash matches
        $expectedHash = hash('sha256', $plainToken);
        $this->assertEquals($expectedHash, $qrCode->token_hash);
        $this->assertDatabaseHas('table_qr_codes', [
            'id' => $qrCode->id,
            'token_hash' => $expectedHash,
            'is_active' => true,
            'generated_by' => $staff->id,
        ]);
    }

    public function test_generating_new_qr_code_transactionally_revokes_previous_one(): void
    {
        $table = RestaurantTable::create(['table_number' => 'T-03', 'capacity' => 6]);

        $firstResult = $this->qrService->generateForTable($table);
        $firstQr = $firstResult['qr_code'];

        $this->assertTrue($firstQr->fresh()->is_active);
        $this->assertNull($firstQr->fresh()->revoked_at);

        $secondResult = $this->qrService->generateForTable($table);
        $secondQr = $secondResult['qr_code'];

        // First QR must now be deactivated and revoked
        $this->assertFalse($firstQr->fresh()->is_active);
        $this->assertNotNull($firstQr->fresh()->revoked_at);

        // Second QR must be active
        $this->assertTrue($secondQr->fresh()->is_active);
        $this->assertNull($secondQr->fresh()->revoked_at);

        // Only 1 active QR code exists for this table
        $activeCount = TableQrCode::where('restaurant_table_id', $table->id)
            ->where('is_active', true)
            ->count();
        $this->assertEquals(1, $activeCount);
    }

    public function test_resolve_table_from_valid_plain_token(): void
    {
        $table = RestaurantTable::create(['table_number' => 'T-04', 'capacity' => 4]);
        $result = $this->qrService->generateForTable($table);

        $resolved = $this->qrService->resolveTableFromToken($result['plain_token']);
        $this->assertNotNull($resolved);
        $this->assertEquals($table->id, $resolved->id);
        $this->assertEquals('T-04', $resolved->table_number);
    }

    public function test_cannot_resolve_table_from_revoked_token(): void
    {
        $table = RestaurantTable::create(['table_number' => 'T-05', 'capacity' => 4]);
        $result = $this->qrService->generateForTable($table);

        $this->qrService->revoke($result['qr_code']);

        $resolved = $this->qrService->resolveTableFromToken($result['plain_token']);
        $this->assertNull($resolved);
    }

    public function test_cannot_resolve_inactive_table(): void
    {
        $table = RestaurantTable::create([
            'table_number' => 'T-06',
            'capacity' => 4,
            'is_active' => false,
        ]);
        $result = $this->qrService->generateForTable($table);

        $resolved = $this->qrService->resolveTableFromToken($result['plain_token']);
        $this->assertNull($resolved);
    }
}
