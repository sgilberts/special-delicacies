<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organisation;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\ReservationTableAllocation;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Stage5ReservationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function venue(): Venue
    {
        return Venue::create([
            'name' => 'Main Dining Room',
            'code' => 'MAIN',
            'venue_type' => Venue::TYPE_DINING_AREA,
            'operational_status' => Venue::STATUS_ACTIVE,
        ]);
    }

    private function customer(string $phone = '0209000001'): Customer
    {
        return Customer::create([
            'name' => 'Ama Owusu',
            'phone' => $phone,
            'email' => 'ama'.$phone.'@example.test',
            'password' => 'correct-horse-2026',
            'is_active' => true,
        ]);
    }

    private function reservation(array $overrides = []): Reservation
    {
        return Reservation::create($overrides + [
            'reference' => 'RES-'.strtoupper(bin2hex(random_bytes(5))),
            'category' => Reservation::CATEGORY_SINGLE_TABLE,
            'reservation_date' => '2026-12-24',
            'start_time' => '19:00',
            'end_time' => '21:00',
            'guest_count' => 4,
            'requested_table_count' => 1,
            'status' => Reservation::STATUS_REQUESTED,
        ]);
    }

    public function test_reservation_links_existing_customer_organisation_and_venue_records(): void
    {
        $customer = $this->customer();
        $organisation = Organisation::create([
            'reference' => 'ORG-RES-001',
            'owner_customer_id' => $customer->id,
            'name' => 'Owusu Holdings',
            'status' => Organisation::STATUS_ACTIVE,
        ]);
        $venue = $this->venue();

        $reservation = $this->reservation([
            'customer_id' => $customer->id,
            'organisation_id' => $organisation->id,
            'venue_id' => $venue->id,
            'category' => Reservation::CATEGORY_EXCLUSIVE_VENUE,
            'guest_count' => 40,
        ]);

        $this->assertSame($customer->id, $reservation->customer->id);
        $this->assertSame($organisation->id, $reservation->organisation->id);
        $this->assertSame($venue->id, $reservation->venue->id);
        $this->assertSame(Reservation::CATEGORY_EXCLUSIVE_VENUE, $reservation->category);
    }

    public function test_multiple_tables_are_represented_as_separate_allocation_records(): void
    {
        $venue = $this->venue();
        $first = RestaurantTable::create(['table_number' => 'T-01', 'capacity' => 4, 'venue_id' => $venue->id]);
        $second = RestaurantTable::create(['table_number' => 'T-02', 'capacity' => 6, 'venue_id' => $venue->id]);

        $reservation = $this->reservation([
            'venue_id' => $venue->id,
            'category' => Reservation::CATEGORY_MULTIPLE_TABLES,
            'requested_table_count' => 2,
        ]);

        ReservationTableAllocation::create([
            'reservation_id' => $reservation->id,
            'restaurant_table_id' => $first->id,
            'occupancy_start' => '2026-12-24 19:00:00',
            'occupancy_end' => '2026-12-24 21:00:00',
        ]);
        ReservationTableAllocation::create([
            'reservation_id' => $reservation->id,
            'restaurant_table_id' => $second->id,
            'occupancy_start' => '2026-12-24 19:00:00',
            'occupancy_end' => '2026-12-24 21:00:00',
        ]);

        $reservation->load('allocations.table');

        $this->assertCount(2, $reservation->allocations);
        $this->assertSame(10, $reservation->allocations->sum(fn ($allocation) => $allocation->table->capacity));
        // The allocation references the shared physical table registry.
        $this->assertSame($first->id, $reservation->allocations->first()->table->id);
        $this->assertDatabaseCount('restaurant_tables', 2);
    }

    public function test_a_table_can_be_allocated_to_different_reservations_in_non_conflicting_periods(): void
    {
        $table = RestaurantTable::create(['table_number' => 'T-09', 'capacity' => 4]);

        $evening = $this->reservation(['reservation_date' => '2026-12-24']);
        $nextDay = $this->reservation(['reservation_date' => '2026-12-25']);

        ReservationTableAllocation::create([
            'reservation_id' => $evening->id,
            'restaurant_table_id' => $table->id,
            'occupancy_start' => '2026-12-24 19:00:00',
            'occupancy_end' => '2026-12-24 21:00:00',
        ]);
        ReservationTableAllocation::create([
            'reservation_id' => $nextDay->id,
            'restaurant_table_id' => $table->id,
            'occupancy_start' => '2026-12-25 19:00:00',
            'occupancy_end' => '2026-12-25 21:00:00',
        ]);

        $this->assertSame(2, $table->reservationAllocations()->count());
    }

    public function test_reservation_reference_must_be_unique(): void
    {
        $this->reservation(['reference' => 'RES-DUPLICATE']);

        $this->expectException(QueryException::class);
        $this->reservation(['reference' => 'RES-DUPLICATE']);
    }

    public function test_reservation_status_history_records_actor_and_metadata(): void
    {
        $actor = User::factory()->create();
        $reservation = $this->reservation(['status' => Reservation::STATUS_CONFIRMED]);

        ReservationStatusHistory::create([
            'reservation_id' => $reservation->id,
            'previous_status' => Reservation::STATUS_REQUESTED,
            'new_status' => Reservation::STATUS_CONFIRMED,
            'changed_by' => $actor->id,
            'reason' => 'Owner confirmed by telephone.',
            'metadata' => ['source' => 'admin'],
        ]);

        $history = $reservation->statusHistories()->firstOrFail();

        $this->assertSame(Reservation::STATUS_REQUESTED, $history->previous_status);
        $this->assertSame(Reservation::STATUS_CONFIRMED, $history->new_status);
        $this->assertSame($actor->id, $history->actor->id);
        $this->assertSame(['source' => 'admin'], $history->metadata);
    }

    public function test_customer_with_reservations_cannot_be_deleted(): void
    {
        $customer = $this->customer();
        $this->reservation(['customer_id' => $customer->id]);

        $this->expectException(QueryException::class);
        $customer->delete();
    }

    public function test_reservation_migrations_roll_back_and_reapply_without_touching_dine_in_tables(): void
    {
        $table = RestaurantTable::create(['table_number' => 'T-77', 'capacity' => 4]);

        $rollback = Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_11_28_000003_create_reservations_tables.php',
            '--realpath' => false,
        ]);
        $this->assertSame(0, $rollback, Artisan::output());
        $this->assertFalse(Schema::hasTable('reservations'));
        $this->assertFalse(Schema::hasTable('reservation_table_allocations'));
        $this->assertFalse(Schema::hasTable('reservation_status_histories'));
        $this->assertTrue(Schema::hasTable('restaurant_tables'));
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table->id, 'table_number' => 'T-77']);

        $migrate = Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_11_28_000003_create_reservations_tables.php',
            '--realpath' => false,
        ]);
        $this->assertSame(0, $migrate, Artisan::output());
        $this->assertTrue(Schema::hasTable('reservations'));
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table->id, 'table_number' => 'T-77']);
    }
}
