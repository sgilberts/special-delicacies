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
        ['View Customers', 'customers.view', 'View customer records and customer profiles'],
        ['Create Customers', 'customers.create', 'Create customer records from the admin portal'],
        ['Update Customers', 'customers.update', 'Edit customer profile and contact details'],
        ['Archive Customers', 'customers.archive', 'Archive a customer while preserving financial and audit history'],
        ['Restore Customers', 'customers.restore', 'Restore an archived customer to active status'],
        ['View Customer Financials', 'customers.view_financials', 'View a customer\'s payments, deposits, invoices, refunds and credit balance'],
    ];

    public function up(): void
    {
        // Archive is a lifecycle state, not a deletion: the row, its orders,
        // receipts, invoices and ledger entries all remain untouched. Existing
        // is_active semantics stay authoritative for account access, so the
        // archive flag is additive and never replaces it.
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_active');
            $table->index('archived_at');
        });

        foreach (self::PERMISSIONS as [$name, $slug, $description]) {
            Permission::updateOrCreate(['slug' => $slug], compact('name', 'description'));
        }

        // Grant only to super-admin, matching RoleAndPermissionSeeder exactly.
        //
        // Customer management is a new capability, so it is not handed to every
        // administrator by default. The seeder is the durable authority — it
        // runs on deploy and calls permissions()->sync() — and its `admin` role
        // deliberately excludes every `customers.*` permission. Granting them
        // here to `admin` would widen access until the next seed and then
        // silently narrow it again. Creating the permissions and granting them
        // to super-admin is the only state both paths agree on; any wider grant
        // is an explicit owner decision made in Roles & Permissions.
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        Role::query()->where('slug', 'super-admin')->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($ids->all()));
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
