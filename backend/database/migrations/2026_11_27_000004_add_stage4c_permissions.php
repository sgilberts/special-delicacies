<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['View Refunds', 'financials.refunds.view', 'View refund records, refundable balances and refund history'],
        ['Create Refund Requests', 'financials.refunds.create', 'Create a refund draft against an eligible payment'],
        ['Submit Refund Requests', 'financials.refunds.submit', 'Submit a refund draft for review and reserve the refundable amount'],
        ['Review Refund Requests', 'financials.refunds.review', 'Move a submitted refund into pending review'],
        ['Approve Refunds', 'financials.refunds.approve', 'Approve or reject a submitted refund request'],
        ['Process Refunds', 'financials.refunds.process', 'Send an approved refund to its destination and record failures'],
        ['Complete Refunds', 'financials.refunds.complete', 'Confirm a processing refund with verified processing evidence'],
        ['Cancel Refund Requests', 'financials.refunds.cancel', 'Cancel a refund that has not been processed'],
        ['Reverse Refunds', 'financials.refunds.reverse', 'Record an audited reversal of a completed refund'],
        ['View Credit Notes', 'financials.credit_notes.view', 'View credit notes and creditable document balances'],
        ['Create Credit Notes', 'financials.credit_notes.create', 'Create and edit credit note drafts'],
        ['Submit Credit Notes', 'financials.credit_notes.submit', 'Submit a credit note draft for approval'],
        ['Approve Credit Notes', 'financials.credit_notes.approve', 'Approve a submitted credit note'],
        ['Issue Credit Notes', 'financials.credit_notes.issue', 'Issue an approved credit note as a formal document'],
        ['Apply Credit Notes', 'financials.credit_notes.apply', 'Apply an issued credit note against its original document'],
        ['Cancel Credit Notes', 'financials.credit_notes.cancel', 'Cancel a credit note before it is issued'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$name, $slug, $description]) {
            Permission::updateOrCreate(['slug' => $slug], compact('name', 'description'));
        }

        // Viewing follows the existing financial transaction register. Every
        // mutating capability is granted only to the existing super-admin role;
        // the owner deliberately assigns it to finance roles after review.
        $viewIds = Permission::query()->whereIn('slug', ['financials.refunds.view', 'financials.credit_notes.view'])->pluck('id');
        Role::query()->whereHas('permissions', fn ($query) => $query->where('slug', 'financials.transactions.view'))
            ->get()->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($viewIds->all()));

        $allIds = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        Role::query()->where('slug', 'super-admin')->get()->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($allIds->all()));
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }
};
