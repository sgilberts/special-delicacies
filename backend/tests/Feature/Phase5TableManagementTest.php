<?php

namespace Tests\Feature;

use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\TableQrCode;
use App\Models\User;
use Database\Seeders\RestaurantSettingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase5TableManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(RestaurantSettingSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('slug', 'super-admin')->first());

        $this->waiter = User::factory()->create();
        $this->waiter->roles()->attach(Role::where('slug', 'waiter-waitress')->first());
    }

    public function test_admin_can_list_tables(): void
    {
        Sanctum::actingAs($this->admin);

        RestaurantTable::create(['table_number' => 'T-01', 'capacity' => 2, 'is_active' => true]);
        RestaurantTable::create(['table_number' => 'T-02', 'capacity' => 4, 'is_active' => false]);

        $response = $this->getJson('/api/v1/admin/tables');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('stats.total', 2)
            ->assertJsonPath('stats.active', 1)
            ->assertJsonPath('stats.inactive', 1)
            ->assertJsonPath('stats.with_qr', 0);
    }

    public function test_admin_can_create_table(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'table_number' => 'T-10',
            'capacity' => 6,
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/admin/tables', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.table_number', 'T-10')
            ->assertJsonPath('data.capacity', 6)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.has_active_qr', false);

        $this->assertDatabaseHas('restaurant_tables', [
            'table_number' => 'T-10',
            'capacity' => 6,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_view_table(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-05', 'capacity' => 4, 'is_active' => true]);

        $response = $this->getJson("/api/v1/admin/tables/{$table->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $table->id)
            ->assertJsonPath('data.table_number', 'T-05')
            ->assertJsonPath('data.has_active_qr', false);
    }

    public function test_admin_can_update_table(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-06', 'capacity' => 4, 'is_active' => true]);

        $response = $this->putJson("/api/v1/admin/tables/{$table->id}", [
            'table_number' => 'T-06-VIP',
            'capacity' => 8,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.table_number', 'T-06-VIP')
            ->assertJsonPath('data.capacity', 8);

        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $table->id,
            'table_number' => 'T-06-VIP',
            'capacity' => 8,
        ]);
    }

    public function test_duplicate_table_number_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        RestaurantTable::create(['table_number' => 'T-07', 'capacity' => 4]);

        $response = $this->postJson('/api/v1/admin/tables', [
            'table_number' => 'T-07',
            'capacity' => 2,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['table_number']);
    }

    public function test_invalid_capacity_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $responseZero = $this->postJson('/api/v1/admin/tables', [
            'table_number' => 'T-08',
            'capacity' => 0,
        ]);

        $responseZero->assertStatus(422)
            ->assertJsonValidationErrors(['capacity']);

        $responseHigh = $this->postJson('/api/v1/admin/tables', [
            'table_number' => 'T-09',
            'capacity' => 51,
        ]);

        $responseHigh->assertStatus(422)
            ->assertJsonValidationErrors(['capacity']);
    }

    public function test_admin_can_toggle_table_status(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-11', 'capacity' => 4, 'is_active' => true]);

        $response = $this->patchJson("/api/v1/admin/tables/{$table->id}/status");

        $response->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($table->fresh()->is_active);

        // Toggle back to active
        $response2 = $this->patchJson("/api/v1/admin/tables/{$table->id}/status");
        $response2->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($table->fresh()->is_active);
    }

    public function test_admin_can_safely_delete_empty_table(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-12', 'capacity' => 4, 'is_active' => true]);

        $response = $this->deleteJson("/api/v1/admin/tables/{$table->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('restaurant_tables', ['id' => $table->id]);
    }

    public function test_deletion_blocked_when_table_has_orders(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-13', 'capacity' => 4, 'is_active' => true]);

        DB::table('orders')->insert([
            'order_number' => 'ORD-TEST-001',
            'order_type' => 'dine_in',
            'restaurant_table_id' => $table->id,
            'customer_name' => 'Kwame Mensah',
            'customer_phone' => '0241234567',
            'status' => 'pending',
            'subtotal' => 60.00,
            'delivery_fee' => 0.00,
            'total' => 60.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/admin/tables/{$table->id}");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('associated orders', $response->json('message'));
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table->id]);
    }

    public function test_unauthenticated_cannot_access_tables(): void
    {
        $response = $this->getJson('/api/v1/admin/tables');
        $response->assertUnauthorized();

        $responsePost = $this->postJson('/api/v1/admin/tables', [
            'table_number' => 'T-UNAUTH',
            'capacity' => 4,
        ]);
        $responsePost->assertUnauthorized();
    }

    public function test_non_admin_cannot_create_table(): void
    {
        Sanctum::actingAs($this->waiter);

        $response = $this->postJson('/api/v1/admin/tables', [
            'table_number' => 'T-FORBIDDEN',
            'capacity' => 4,
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_generate_qr_code(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-14', 'capacity' => 4]);

        $response = $this->postJson("/api/v1/admin/tables/{$table->id}/qr/generate");

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                    'qr_url',
                    'qr_id',
                    'table' => ['id', 'table_number', 'capacity', 'is_active'],
                ],
            ]);

        $plainToken = $response->json('data.token');
        $this->assertEquals(64, strlen($plainToken));
        $this->assertStringContainsString("/table/?token={$plainToken}", $response->json('data.qr_url'));

        // Check token_hash is stored in DB, but not the plain token
        $this->assertDatabaseMissing('table_qr_codes', ['token_hash' => $plainToken]);
        $this->assertDatabaseHas('table_qr_codes', [
            'restaurant_table_id' => $table->id,
            'token_hash' => hash('sha256', $plainToken),
            'is_active' => true,
        ]);
    }

    public function test_qr_tokens_are_unique_across_generations(): void
    {
        Sanctum::actingAs($this->admin);

        $table1 = RestaurantTable::create(['table_number' => 'T-15A', 'capacity' => 4]);
        $table2 = RestaurantTable::create(['table_number' => 'T-15B', 'capacity' => 4]);

        $res1 = $this->postJson("/api/v1/admin/tables/{$table1->id}/qr/generate");
        $res2 = $this->postJson("/api/v1/admin/tables/{$table2->id}/qr/generate");

        $token1 = $res1->json('data.token');
        $token2 = $res2->json('data.token');

        $this->assertNotEquals($token1, $token2);
    }

    public function test_existing_qr_urls_are_migrated_to_query_token_format(): void
    {
        // Pin the public frontend URL so the expected QR URL does not depend on
        // the container's FRONTEND_URL value.
        config(['app.frontend_url' => 'https://special-delicacies.local']);

        Sanctum::actingAs($this->admin);
        $table = RestaurantTable::create(['table_number' => 'T-QR-URL', 'capacity' => 4]);
        $generated = $this->postJson("/api/v1/admin/tables/{$table->id}/qr/generate");
        $token = $generated->json('data.token');
        Cache::put("table_qr_url_{$table->id}", "https://special-delicacies.local/table/{$token}");

        $response = $this->getJson("/api/v1/admin/tables/{$table->id}/qr");

        $response->assertOk()->assertJsonPath(
            'data.qr_url',
            "https://special-delicacies.local/table/?token={$token}"
        );
    }

    public function test_admin_can_regenerate_qr_code(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-16', 'capacity' => 4]);

        $genRes = $this->postJson("/api/v1/admin/tables/{$table->id}/qr/generate");
        $firstQrId = $genRes->json('data.qr_id');
        $firstToken = $genRes->json('data.token');

        $regenRes = $this->postJson("/api/v1/admin/tables/{$table->id}/qr/regenerate");
        $regenRes->assertOk()
            ->assertJsonPath('success', true);

        $secondQrId = $regenRes->json('data.qr_id');
        $secondToken = $regenRes->json('data.token');

        $this->assertNotEquals($firstQrId, $secondQrId);
        $this->assertNotEquals($firstToken, $secondToken);

        // Previous QR code is revoked and inactive
        $this->assertDatabaseHas('table_qr_codes', [
            'id' => $firstQrId,
            'is_active' => false,
        ]);
        $this->assertNotNull(TableQrCode::find($firstQrId)->revoked_at);

        // New QR code is active
        $this->assertDatabaseHas('table_qr_codes', [
            'id' => $secondQrId,
            'is_active' => true,
            'revoked_at' => null,
        ]);
    }

    public function test_admin_can_revoke_qr(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-17', 'capacity' => 4]);

        $genRes = $this->postJson("/api/v1/admin/tables/{$table->id}/qr/generate");
        $qrId = $genRes->json('data.qr_id');

        $revokeRes = $this->deleteJson("/api/v1/admin/tables/{$table->id}/qr/revoke");
        $revokeRes->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('table_qr_codes', [
            'id' => $qrId,
            'is_active' => false,
        ]);
        $this->assertNotNull(TableQrCode::find($qrId)->revoked_at);
    }

    public function test_admin_can_delete_a_revoked_qr_record(): void
    {
        Sanctum::actingAs($this->admin);
        $table = RestaurantTable::create(['table_number' => 'T-QR-DELETE', 'capacity' => 4]);
        $generated = $this->postJson("/api/v1/admin/tables/{$table->id}/qr/generate");
        $qrId = $generated->json('data.qr_id');
        $this->deleteJson("/api/v1/admin/tables/{$table->id}/qr/revoke")->assertOk();

        $this->deleteJson("/api/v1/admin/tables/qr/{$qrId}")->assertOk();

        $this->assertDatabaseMissing('table_qr_codes', ['id' => $qrId]);
    }

    public function test_admin_can_create_update_and_delete_custom_roles(): void
    {
        Sanctum::actingAs($this->admin);
        $created = $this->postJson('/api/v1/admin/roles', [
            'name' => 'Floor Lead', 'slug' => 'floor-lead', 'description' => 'Floor operations',
            'permission_slugs' => ['menu.items.view'],
        ]);
        $created->assertCreated()->assertJsonPath('data.name', 'Floor Lead');
        $roleId = $created->json('data.id');

        $this->putJson("/api/v1/admin/roles/{$roleId}", [
            'name' => 'Senior Floor Lead', 'slug' => 'senior-floor-lead', 'description' => 'Updated scope',
        ])->assertOk()->assertJsonPath('data.slug', 'senior-floor-lead');
        $this->putJson("/api/v1/admin/roles/{$roleId}/permissions", [
            'permission_slugs' => ['menu.items.view'],
        ])->assertOk();

        $this->deleteJson("/api/v1/admin/roles/{$roleId}")->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    public function test_revoked_qr_returns_404_on_public_resolve(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-18', 'capacity' => 4]);
        $genRes = $this->postJson("/api/v1/admin/tables/{$table->id}/qr/generate");
        $token = $genRes->json('data.token');

        // Revoke the QR code
        $this->deleteJson("/api/v1/admin/tables/{$table->id}/qr/revoke");

        // Attempt public lookup
        $publicRes = $this->getJson("/api/v1/table-lookup/{$token}");
        $publicRes->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_inactive_table_returns_404_on_public_resolve(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-19', 'capacity' => 4, 'is_active' => true]);
        $genRes = $this->postJson("/api/v1/admin/tables/{$table->id}/qr/generate");
        $token = $genRes->json('data.token');

        // Deactivate table
        $table->update(['is_active' => false]);

        // Attempt public lookup
        $publicRes = $this->getJson("/api/v1/table-lookup/{$token}");
        $publicRes->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_invalid_token_returns_404_on_public_resolve(): void
    {
        $fakeToken = str_repeat('a', 64);
        $publicRes = $this->getJson("/api/v1/table-lookup/{$fakeToken}");
        $publicRes->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_public_resolution_does_not_expose_token_hash(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-20', 'capacity' => 4, 'is_active' => true]);
        $genRes = $this->postJson("/api/v1/admin/tables/{$table->id}/qr/generate");
        $token = $genRes->json('data.token');

        $publicRes = $this->getJson("/api/v1/table-lookup/{$token}");
        $publicRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.table_number', 'T-20');

        $data = $publicRes->json('data');
        $this->assertArrayNotHasKey('token_hash', $data);
        $this->assertArrayNotHasKey('token', $data);
        $this->assertStringNotContainsString(hash('sha256', $token), $publicRes->getContent());
    }

    public function test_non_admin_cannot_generate_qr(): void
    {
        Sanctum::actingAs($this->waiter);

        $table = RestaurantTable::create(['table_number' => 'T-21', 'capacity' => 4]);

        $response = $this->postJson("/api/v1/admin/tables/{$table->id}/qr/generate");
        $response->assertForbidden();
    }

    public function test_non_admin_cannot_revoke_qr(): void
    {
        $table = RestaurantTable::create(['table_number' => 'T-22', 'capacity' => 4]);

        Sanctum::actingAs($this->waiter);

        $response = $this->deleteJson("/api/v1/admin/tables/{$table->id}/qr/revoke");
        $response->assertForbidden();
    }

    public function test_deletion_blocked_when_table_has_qr_codes(): void
    {
        Sanctum::actingAs($this->admin);

        $table = RestaurantTable::create(['table_number' => 'T-23', 'capacity' => 4, 'is_active' => true]);
        TableQrCode::create([
            'restaurant_table_id' => $table->id,
            'token_hash' => hash('sha256', 'sample_plain_token_23'),
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/v1/admin/tables/{$table->id}");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('associated QR code records', $response->json('message'));
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table->id]);
    }

    public function test_waiter_kitchen_and_delivery_staff_receive_403_on_tables(): void
    {
        $kitchenStaff = User::factory()->create();
        $kitchenStaff->roles()->attach(Role::where('slug', 'kitchen-staff')->first());

        $deliveryStaff = User::factory()->create();
        $deliveryStaff->roles()->attach(Role::where('slug', 'delivery-staff')->first());

        $table = RestaurantTable::create(['table_number' => 'T-24', 'capacity' => 4, 'is_active' => true]);

        // Waiter/Waitress
        Sanctum::actingAs($this->waiter);
        $this->getJson('/api/v1/admin/tables')->assertForbidden();
        $this->getJson("/api/v1/admin/tables/{$table->id}")->assertForbidden();

        // Kitchen Staff
        Sanctum::actingAs($kitchenStaff);
        $this->getJson('/api/v1/admin/tables')->assertForbidden();
        $this->getJson("/api/v1/admin/tables/{$table->id}")->assertForbidden();

        // Delivery Staff
        Sanctum::actingAs($deliveryStaff);
        $this->getJson('/api/v1/admin/tables')->assertForbidden();
        $this->getJson("/api/v1/admin/tables/{$table->id}")->assertForbidden();
    }

    public function test_inactive_account_blocked_from_tables(): void
    {
        $inactiveAdmin = User::factory()->create(['is_active' => false]);
        $inactiveAdmin->roles()->attach(Role::where('slug', 'super-admin')->first());

        Sanctum::actingAs($inactiveAdmin);

        $response = $this->getJson('/api/v1/admin/tables');
        $response->assertForbidden()
            ->assertJsonPath('message', 'Your account is deactivated.');
    }
}
