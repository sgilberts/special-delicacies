<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['View Payment Allocations', 'financials.allocations.view', 'View eligible invoice receipts and payment allocation history'],
        ['Manage Payment Allocations', 'financials.allocations.manage', 'Allocate eligible receipts to issued invoices'],
        ['Reverse Payment Allocations', 'financials.allocations.reverse', 'Reverse an allocation with an audited reason'],
        ['View Invoice Payment Balances', 'financials.invoice_balances.view', 'View derived invoice payment balances'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$name, $slug, $description]) {
            Permission::updateOrCreate(['slug' => $slug], compact('name', 'description'));
        }

        // Viewing follows existing transaction-register access. Mutations are
        // granted only to the existing super-admin role; an owner can assign
        // them deliberately to finance roles after review.
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        Role::query()->whereHas('permissions', fn ($query) => $query->where('slug', 'financials.transactions.view'))
            ->get()->each(function (Role $role): void {
                $viewIds = Permission::query()->whereIn('slug', ['financials.allocations.view', 'financials.invoice_balances.view'])->pluck('id');
                $role->permissions()->syncWithoutDetaching($viewIds->all());
            });
        Role::query()->where('slug', 'super-admin')->get()->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($ids->all()));
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }
};
