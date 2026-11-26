<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['View Commercial Documents', 'commercial_documents.view', 'View quotations and invoices'],
        ['Manage Commercial Documents', 'commercial_documents.manage', 'Create and edit draft quotations and invoices'],
        ['Issue Commercial Documents', 'commercial_documents.issue', 'Issue quotations and invoices'],
        ['Void Commercial Documents', 'commercial_documents.void', 'Void issued invoices with an audited reason'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$name, $slug, $description]) {
            Permission::updateOrCreate(['slug' => $slug], compact('name', 'description'));
        }

        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        Role::query()->whereIn('slug', ['admin', 'super-admin'])->each(function (Role $role) use ($ids): void {
            $role->permissions()->syncWithoutDetaching($ids->all());
        });
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }
};
