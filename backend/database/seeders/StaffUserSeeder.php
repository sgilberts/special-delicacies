<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StaffUserSeeder extends Seeder
{
    /**
     * Run the database seeds for operational staff.
     */
    public function run(): void
    {
        $waiterRole = Role::where('slug', 'waiter-waitress')->first();
        $kitchenRole = Role::where('slug', 'kitchen-staff')->first();
        $deliveryRole = Role::where('slug', 'delivery-staff')->first();

        // Waiter staff user
        $waiter = User::firstOrCreate(
            ['email' => 'waiter@special-delicacies.local'],
            [
                'name' => 'Kofi Mensah',
                'phone' => '+233200000002',
                'password' => Hash::make(env('SEED_WAITER_PASSWORD', Str::random(32))),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        if ($waiterRole && ! $waiter->hasRole('waiter-waitress')) {
            $waiter->roles()->syncWithoutDetaching([$waiterRole->id]);
        }

        // Kitchen staff user
        $kitchen = User::firstOrCreate(
            ['email' => 'kitchen@special-delicacies.local'],
            [
                'name' => 'Chef Kwame',
                'phone' => '+233200000003',
                'password' => Hash::make(env('SEED_KITCHEN_PASSWORD', Str::random(32))),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        if ($kitchenRole && ! $kitchen->hasRole('kitchen-staff')) {
            $kitchen->roles()->syncWithoutDetaching([$kitchenRole->id]);
        }

        // Delivery staff user
        $delivery = User::firstOrCreate(
            ['email' => 'delivery@special-delicacies.local'],
            [
                'name' => 'Emmanuel Rider',
                'phone' => '+233200000004',
                'password' => Hash::make(env('SEED_DELIVERY_PASSWORD', Str::random(32))),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        if ($deliveryRole && ! $delivery->hasRole('delivery-staff')) {
            $delivery->roles()->syncWithoutDetaching([$deliveryRole->id]);
        }
    }
}
