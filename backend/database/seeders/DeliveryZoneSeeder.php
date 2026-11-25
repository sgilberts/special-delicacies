<?php

namespace Database\Seeders;

use App\Models\DeliveryZone;
use Illuminate\Database\Seeder;

class DeliveryZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $zones = [
            [
                'name'         => 'Accra Central',
                'description'  => 'Ministries, High Street, Makola, and Central Business District',
                'delivery_fee' => 15.00,
                'is_active'    => true,
            ],
            [
                'name'         => 'Osu & Oxford Street',
                'description'  => 'Osu, Ring Road East, and coastal commercial area',
                'delivery_fee' => 15.00,
                'is_active'    => true,
            ],
            [
                'name'         => 'Airport Residential Area',
                'description'  => 'Airport Residential, Kotoka International Airport, and environs',
                'delivery_fee' => 20.00,
                'is_active'    => true,
            ],
            [
                'name'         => 'Cantonments & Labone',
                'description'  => 'Cantonments embassies district, Labone, and South La',
                'delivery_fee' => 20.00,
                'is_active'    => true,
            ],
            [
                'name'         => 'East Legon & Shiashie',
                'description'  => 'East Legon residential area, American House, and Shiashie',
                'delivery_fee' => 25.00,
                'is_active'    => true,
            ],
            [
                'name'         => 'Dzorwulu & Roman Ridge',
                'description'  => 'Dzorwulu, Roman Ridge, and Abelemkpe',
                'delivery_fee' => 20.00,
                'is_active'    => true,
            ],
        ];

        foreach ($zones as $zoneData) {
            DeliveryZone::firstOrCreate(
                ['name' => $zoneData['name']],
                $zoneData
            );
        }
    }
}
