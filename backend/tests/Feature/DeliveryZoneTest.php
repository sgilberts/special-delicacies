<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_delivery_zone(): void
    {
        $zone = DeliveryZone::create([
            'name' => 'Downtown Area',
            'delivery_fee' => 4.50,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('delivery_zones', [
            'name' => 'Downtown Area',
            'is_active' => true,
        ]);
        $this->assertEquals('4.50', $zone->delivery_fee);
    }

    public function test_delivery_zone_name_must_be_unique(): void
    {
        DeliveryZone::create([
            'name' => 'Uptown',
            'delivery_fee' => 5.00,
        ]);

        $this->expectException(QueryException::class);

        DeliveryZone::create([
            'name' => 'Uptown',
            'delivery_fee' => 7.00,
        ]);
    }

    public function test_active_scope_filters_inactive_zones(): void
    {
        DeliveryZone::create(['name' => 'Active Zone', 'delivery_fee' => 3.00, 'is_active' => true]);
        DeliveryZone::create(['name' => 'Inactive Zone', 'delivery_fee' => 6.00, 'is_active' => false]);

        $activeZones = DeliveryZone::active()->get();
        $this->assertCount(1, $activeZones);
        $this->assertEquals('Active Zone', $activeZones->first()->name);
    }
}
