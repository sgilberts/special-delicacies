<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Idempotently create an initial administrative user for Phase 4 Menu Management.
     */
    public function run(): void
    {
        $adminRole = Role::where('slug', 'super-admin')->first();

        $admin = User::firstOrCreate(
            ['email' => 'super-admin@special-delicacies.local'],
            [
                'name' => 'Restaurant Super Admin',
                'phone' => '+233200000001',
                'password' => Hash::make(env('SEED_ADMIN_PASSWORD', Str::random(32))),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        if ($adminRole && ! $admin->hasRole('super-admin')) {
            $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        }
    }
}
