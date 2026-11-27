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

        // View permissions follow the existing read access for each area.
        $this->grantToRolesWith('financials.refunds.view', 'financials.transactions.view');
        $this->grantToRolesWith('financials.credit_notes.view', 'commercial_documents.view');
        $this->grantToRolesWith('financials.credit_reversals.view', 'financials.customer_credit.view');

        // Mutations are granted only to super-admin; the owner assigns them to
        // finance roles deliberately after reviewing D1–D13.
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

    private function grantToRolesWith(string $viewSlug, string $existingSlug): void
    {
        $permissionId = Permission::query()->where('slug', $viewSlug)->value('id');
        if (! $permissionId) return;

        Role::query()->whereHas('permissions', fn ($query) => $query->where('slug', $existingSlug))
            ->get()->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permissionId]));
    }
};
