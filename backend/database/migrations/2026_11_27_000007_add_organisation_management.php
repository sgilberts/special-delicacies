<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['View Organisations', 'organisations.view', 'View organisation records, memberships and profiles'],
        ['Create Organisations', 'organisations.create', 'Create organisation records from the admin portal'],
        ['Update Organisations', 'organisations.update', 'Edit organisation profile and contact details'],
        ['Archive Organisations', 'organisations.archive', 'Archive an organisation while preserving members, documents and history'],
        ['Restore Organisations', 'organisations.restore', 'Restore an archived organisation to active status'],
        ['Manage Organisation Members', 'organisations.manage_members', 'View organisation membership lists and membership status'],
        ['View Organisation Financials', 'organisations.view_financials', 'View an organisation\'s payments, deposits, invoices, refunds and credit balance'],
    ];

    public function up(): void
    {
        // Archiving is a lifecycle state, never a deletion: the organisation
        // row, its memberships, commercial documents and ledger entries all
        // remain untouched. `status` keeps its existing active/suspended
        // meaning, so the archive flag is additive.
        Schema::table('organisations', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->index('archived_at');
            $table->index('name');
        });

        foreach (self::PERMISSIONS as [$name, $slug, $description]) {
            Permission::updateOrCreate(['slug' => $slug], compact('name', 'description'));
        }

        // Organisations are a new capability, so — exactly like customer
        // management — they are not handed to every administrator by default.
        // The seeder is the durable authority and excludes `organisations.*`
        // from the `admin` role, so granting them here would only widen access
        // until the next seed. Creating the permissions and granting them to
        // super-admin is the only state both paths agree on; any wider grant is
        // an explicit owner decision in Roles & Permissions.
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        Role::query()->where('slug', 'super-admin')->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($ids->all()));
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropIndex(['name']);
            $table->dropColumn('archived_at');
        });
    }
};
