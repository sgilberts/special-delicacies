<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $items = [
            ['View Financial Dashboard', 'financials.view', 'View financial dashboard and aggregates'],
            ['View Financial Transactions', 'financials.transactions.view', 'View transaction register and payment details'],
            ['View Financial Reports', 'financials.reports.view', 'View financial reports'],
            ['Export Financial Reports', 'financials.reports.export', 'Export validated financial reports'],
            ['View Payment Reconciliation', 'financials.reconciliation.view', 'View payment reconciliation queue'],
            ['Confirm Cash Payments', 'financials.payments.confirm_cash', 'Confirm cash collection for an order'],
        ];
        foreach ($items as [$name, $slug, $description]) {
            Permission::updateOrCreate(['slug' => $slug], compact('name', 'description'));
        }

        $all = Permission::query()->get();
        $admin = Role::firstOrCreate(['slug' => 'admin'], [
            'name' => 'Admin',
            'description' => 'Restaurant administrator; access is controlled by assigned permissions.',
        ]);
        $admin->permissions()->sync($all->reject(fn (Permission $permission) => str_starts_with($permission->slug, 'financials.'))->modelKeys());
        Role::where('slug', 'super-admin')->first()?->permissions()->syncWithoutDetaching($all->modelKeys());

    }

    public function down(): void
    {
        $admin = Role::where('slug', 'admin')->first();
        if ($admin && $admin->users()->doesntExist()) {
            $admin->permissions()->detach();
            $admin->delete();
        }
        $ids = Permission::whereIn('slug', [
            'financials.view', 'financials.transactions.view', 'financials.reports.view',
            'financials.reports.export', 'financials.reconciliation.view', 'financials.payments.confirm_cash',
        ])->pluck('id');
        \Illuminate\Support\Facades\DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::whereIn('id', $ids)->delete();
    }
};
