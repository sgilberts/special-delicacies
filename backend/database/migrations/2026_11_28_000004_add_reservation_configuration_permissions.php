<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 5.2 — reservation configuration permissions.
 *
 * Registers the capabilities only. Existing users and roles are deliberately
 * not granted anything here; the project owner assigns access through the
 * existing Roles & Permissions screen.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['View Venues', 'venues.view', 'View reservable venues and their configuration'],
        ['Create Venues', 'venues.create', 'Create reservable venues'],
        ['Update Venues', 'venues.update', 'Edit venue details and reservation availability settings'],
        ['Delete Venues', 'venues.delete', 'Delete venues that have no tables or reservations'],
        ['Manage Venue Configuration', 'venues.configuration.manage', 'Manage operating hours, special hours, closures and maintenance periods'],
        ['Manage Table Availability', 'tables.availability.manage', 'Manage temporarily unavailable table periods and maintenance'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$name, $slug, $description]) {
            Permission::updateOrCreate(['slug' => $slug], compact('name', 'description'));
        }
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }
};
