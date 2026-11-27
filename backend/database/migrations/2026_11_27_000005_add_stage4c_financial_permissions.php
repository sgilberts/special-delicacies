<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['View Refunds', 'financials.refunds.view', 'View refund requests, provider status and refund history'],
        ['Initiate Refunds', 'financials.refunds.initiate', 'Request a refund against an eligible payment receipt'],
        ['Approve Refunds', 'financials.refunds.approve', 'Approve, submit and confirm or reject refund requests'],
        ['View Credit Notes', 'financials.credit_notes.view', 'View credit notes and their originating invoices'],
        ['Create Credit Notes', 'financials.credit_notes.create', 'Create draft credit notes against eligible invoices'],
        ['Approve Credit Notes', 'financials.credit_notes.approve', 'Issue or void a draft credit note'],
        ['View Credit Reversals', 'financials.credit_reversals.view', 'View customer credit reversal requests and history'],
        ['Initiate Credit Reversals', 'financials.credit_reversals.initiate', 'Request a reversal of unspent customer credit'],
        ['Approve Credit Reversals', 'financials.credit_reversals.approve', 'Approve or reject a customer credit reversal'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$name, $slug, $description]) {
            Permission::updateOrCreate(['slug' => $slug], compact('name', 'description'));
        }

        // Grant only to super-admin, matching RoleAndPermissionSeeder exactly.
        //
        // The Stage 4C design (§12) originally proposed copying the three view
        // permissions to every role holding the related read permission. The
        // seeder is the durable authority — it runs on deploy and calls
        // permissions()->sync(), which would silently remove those grants — and
        // its `admin` role deliberately excludes all `financials.*` permissions.
        // Copying them here would therefore widen access for `admin` until the
        // next seed, and then narrow it again. Creating the permissions and
        // granting them to super-admin is the only state both paths agree on;
        // any wider grant is an explicit owner decision made in the UI.
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        Role::query()->where('slug', 'super-admin')->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($ids->all()));
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }

};
