<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RestaurantSettingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Stage5VenueTableConfigurationTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function venuePayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Main Dining Room',
            'code' => 'MAIN',
            'description' => 'Ground floor dining',
            'venue_type' => Venue::TYPE_DINING_AREA,
            'location' => 'Ground floor',
            'max_capacity' => 60,
            'operational_status' => Venue::STATUS_ACTIVE,
            'reservation_enabled' => true,
            'exclusive_reservation_enabled' => true,
            'default_reservation_duration_minutes' => 120,
            'reservation_slot_minutes' => 30,
            'internal_notes' => 'Staff only note.',
        ];
    }

    private function createVenue(string $code = 'MAIN'): Venue
    {
        return Venue::create([
            'name' => 'Venue '.$code,
            'code' => $code,
            'venue_type' => Venue::TYPE_DINING_AREA,
            'operational_status' => Venue::STATUS_ACTIVE,
        ]);
    }

    private function activeDineInOrder(RestaurantTable $table, string $status = Order::STATUS_CONFIRMED): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.strtoupper(bin2hex(random_bytes(4))),
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $table->id,
            'customer_name' => 'Walk-in Guest',
            'customer_phone' => '+233200000099',
            'status' => $status,
            'subtotal' => 50,
            'total' => 50,
        ]);
    }

    public function test_admin_can_create_and_update_a_venue_with_audit(): void
    {
        Sanctum::actingAs($this->admin);

        $created = $this->postJson('/api/v1/admin/venues', $this->venuePayload());
        $created->assertCreated()
            ->assertJsonPath('data.code', 'MAIN')
            ->assertJsonPath('data.venue_type', Venue::TYPE_DINING_AREA)
            ->assertJsonPath('data.exclusive_reservation_enabled', true);

        $venueId = $created->json('data.id');

        $this->putJson("/api/v1/admin/venues/{$venueId}", $this->venuePayload([
            'name' => 'Main Dining Room (Renovated)',
            'max_capacity' => 72,
            'operational_status' => Venue::STATUS_MAINTENANCE,
        ]))->assertOk()
            ->assertJsonPath('data.name', 'Main Dining Room (Renovated)')
            ->assertJsonPath('data.max_capacity', 72)
            ->assertJsonPath('data.operational_status', Venue::STATUS_MAINTENANCE);

        $this->assertDatabaseHas('venues', ['id' => $venueId, 'name' => 'Main Dining Room (Renovated)', 'max_capacity' => 72]);
        $this->assertTrue(AdminAuditLog::where('action', 'venue.created')->exists());
        $this->assertTrue(AdminAuditLog::where('action', 'venue.updated')->exists());
    }

    public function test_venue_code_is_unique_and_validated(): void
    {
        Sanctum::actingAs($this->admin);
        $this->createVenue('MAIN');

        $this->postJson('/api/v1/admin/venues', $this->venuePayload(['code' => 'MAIN']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/admin/venues', $this->venuePayload(['code' => 'bad code!']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_staff_without_permission_cannot_manage_venues(): void
    {
        Sanctum::actingAs($this->waiter);

        $this->getJson('/api/v1/admin/venues')->assertForbidden();
        $this->postJson('/api/v1/admin/venues', $this->venuePayload())->assertForbidden();
    }

    public function test_admin_can_associate_a_table_with_a_venue_and_configure_reservation_attributes(): void
    {
        Sanctum::actingAs($this->admin);
        $venue = $this->createVenue();

        $response = $this->postJson('/api/v1/admin/tables', [
            'table_number' => 'T-20',
            'capacity' => 8,
            'is_active' => true,
            'venue_id' => $venue->id,
            'table_type' => RestaurantTable::TYPE_BOOTH,
            'operational_status' => RestaurantTable::OPERATIONAL_AVAILABLE,
            'reservation_eligible' => true,
            'seating_configuration' => ['seats' => 8, 'layout' => 'banquette'],
            'admin_notes' => 'Near the window.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.venue_id', $venue->id)
            ->assertJsonPath('data.table_type', RestaurantTable::TYPE_BOOTH)
            ->assertJsonPath('data.reservation_eligible', true)
            ->assertJsonPath('data.venue.code', $venue->code);

        $tableId = $response->json('data.id');

        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $tableId,
            'venue_id' => $venue->id,
            'table_type' => RestaurantTable::TYPE_BOOTH,
            'capacity' => 8,
        ]);

        $this->putJson("/api/v1/admin/tables/{$tableId}", [
            'capacity' => 10,
            'reservation_eligible' => false,
            'operational_status' => RestaurantTable::OPERATIONAL_MAINTENANCE,
            'admin_notes' => 'Under repair.',
        ])->assertOk()
            ->assertJsonPath('data.capacity', 10)
            ->assertJsonPath('data.reservation_eligible', false)
            ->assertJsonPath('data.operational_status', RestaurantTable::OPERATIONAL_MAINTENANCE);

        $this->assertTrue(AdminAuditLog::where('action', 'table.updated')->exists());
    }

    public function test_existing_table_payload_still_works_with_reservation_defaults(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/tables', [
            'table_number' => 'T-LEGACY',
            'capacity' => 4,
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.table_number', 'T-LEGACY')
            ->assertJsonPath('data.venue_id', null)
            ->assertJsonPath('data.operational_status', RestaurantTable::OPERATIONAL_AVAILABLE)
            ->assertJsonPath('data.reservation_eligible', true);
    }

    public function test_table_with_active_dine_in_order_cannot_be_taken_out_of_service(): void
    {
        Sanctum::actingAs($this->admin);
        $table = RestaurantTable::create(['table_number' => 'T-30', 'capacity' => 4]);
        $order = $this->activeDineInOrder($table);

        $this->putJson("/api/v1/admin/tables/{$table->id}", [
            'operational_status' => RestaurantTable::OPERATIONAL_MAINTENANCE,
        ])->assertUnprocessable();

        $this->assertSame(RestaurantTable::OPERATIONAL_AVAILABLE, $table->refresh()->operational_status);

        // The restriction lifts only when the order reaches a terminal status.
        $order->update(['status' => Order::STATUS_COMPLETED]);

        $this->putJson("/api/v1/admin/tables/{$table->id}", [
            'operational_status' => RestaurantTable::OPERATIONAL_MAINTENANCE,
        ])->assertOk()
            ->assertJsonPath('data.operational_status', RestaurantTable::OPERATIONAL_MAINTENANCE);
    }

    public function test_operating_hours_special_hours_and_closures_are_configured_and_audited(): void
    {
        Sanctum::actingAs($this->admin);
        $venue = $this->createVenue();

        $hours = collect(range(0, 6))->map(fn (int $day) => [
            'day_of_week' => $day,
            'opens_at' => $day === 1 ? null : '11:00',
            'closes_at' => $day === 1 ? null : '22:00',
            'is_closed' => $day === 1,
        ])->all();

        $this->putJson("/api/v1/admin/venues/{$venue->id}/operating-hours", ['hours' => $hours])
            ->assertOk()
            ->assertJsonCount(7, 'data');

        $this->assertDatabaseCount('venue_operating_hours', 7);
        $this->assertDatabaseHas('venue_operating_hours', [
            'venue_id' => $venue->id,
            'day_of_week' => 1,
            'is_closed' => true,
        ]);

        $this->postJson("/api/v1/admin/venues/{$venue->id}/special-hours", [
            'date' => '2026-12-25',
            'opens_at' => '13:00',
            'closes_at' => '18:00',
            'is_closed' => false,
            'reason' => 'Christmas Day hours',
        ])->assertCreated()->assertJsonPath('data.is_closed', false);

        $this->assertDatabaseHas('venue_special_hours', [
            'venue_id' => $venue->id,
            'date' => '2026-12-25',
            'reason' => 'Christmas Day hours',
        ]);

        $this->postJson("/api/v1/admin/venues/{$venue->id}/closures", [
            'closure_type' => 'maintenance',
            'starts_at' => '2026-12-26 08:00:00',
            'ends_at' => '2026-12-26 12:00:00',
            'reason' => 'Floor polishing',
        ])->assertCreated()->assertJsonPath('data.closure_type', 'maintenance');

        $this->assertTrue(AdminAuditLog::where('action', 'venue.operating_hours.updated')->exists());
        $this->assertTrue(AdminAuditLog::where('action', 'venue.special_hour.created')->exists());
        $this->assertTrue(AdminAuditLog::where('action', 'venue.closure.created')->exists());

        $venue->load(['operatingHours', 'specialHours', 'closures']);
        $this->getJson("/api/v1/admin/venues/{$venue->id}")
            ->assertOk()
            ->assertJsonCount(7, 'data.operating_hours')
            ->assertJsonCount(1, 'data.special_hours')
            ->assertJsonCount(1, 'data.closures');
    }

    public function test_table_unavailability_periods_are_managed(): void
    {
        Sanctum::actingAs($this->admin);
        $table = RestaurantTable::create(['table_number' => 'T-40', 'capacity' => 4]);

        $created = $this->postJson("/api/v1/admin/tables/{$table->id}/unavailability", [
            'starts_at' => '2026-12-27 09:00:00',
            'ends_at' => '2026-12-27 17:00:00',
            'reason' => 'Upholstery cleaning',
        ])->assertCreated();

        $periodId = $created->json('data.id');

        $this->getJson("/api/v1/admin/tables/{$table->id}/unavailability")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/admin/tables/{$table->id}/unavailability/{$periodId}")
            ->assertOk();

        $this->assertDatabaseCount('table_unavailability_periods', 0);
        $this->assertTrue(AdminAuditLog::where('action', 'table.unavailability.created')->exists());
        $this->assertTrue(AdminAuditLog::where('action', 'table.unavailability.deleted')->exists());
    }

    public function test_venue_with_tables_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->admin);
        $venue = $this->createVenue();
        $table = RestaurantTable::create(['table_number' => 'T-50', 'capacity' => 4, 'venue_id' => $venue->id]);

        $this->deleteJson("/api/v1/admin/venues/{$venue->id}")->assertUnprocessable();

        $table->delete();

        $this->deleteJson("/api/v1/admin/venues/{$venue->id}")->assertOk();
        $this->assertDatabaseMissing('venues', ['id' => $venue->id]);
    }
}
