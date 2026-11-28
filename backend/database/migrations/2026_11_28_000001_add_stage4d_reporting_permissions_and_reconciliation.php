<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage 4D adds read-only reporting permissions, reconciliation preparation
     * and review permissions, report indexes and the reconciliation run history.
     *
     * Permissions are granted to super-admin only, matching the existing posture:
     * the `admin` role is deliberately seeded without any `financials.*`
     * permission, and widening it here would contradict the least-privilege
     * decision recorded in docs/STAGE-4D-PERMISSIONS-MATRIX.md.
     */
    private const PERMISSIONS = [
        ['View Financial Dashboard', 'financials.reports.dashboard.view', 'View the financial management dashboard aggregates, trends and operational summaries'],
        ['View Payment and Receipt Register', 'financials.reports.receipts.view', 'View the payment and receipt register report'],
        ['View Payment Allocation Report', 'financials.reports.allocations.view', 'View receipt allocation and allocation reversal reporting'],
        ['View Unallocated Receipts Report', 'financials.reports.unallocated.view', 'View successful receipts that remain available for allocation'],
        ['View Accounts Receivable Report', 'financials.reports.receivables.view', 'View invoice receivables, ageing and outstanding balances'],
        ['View Customer Credit Report', 'financials.reports.customer_credit.view', 'View customer credit balances, movements and ledger statements'],
        ['View Organisation Credit Report', 'financials.reports.organisation_credit.view', 'View organisation credit balances, movements and ledger statements'],
        ['View Refund Report', 'financials.reports.refunds.view', 'View refund lifecycle reporting by stage'],
        ['View Credit Note Report', 'financials.reports.credit_notes.view', 'View credit note reporting and application status'],
        ['View Financial Activity Report', 'financials.reports.activity.view', 'View the chronological financial activity and audit report'],
        ['View Settlement Reports', 'financials.reports.settlements.view', 'View provider settlement reconciliation reporting where genuine source data exists'],
        ['Prepare Reconciliations', 'financials.reconciliation.manage', 'Prepare internal reconciliation runs and record findings'],
        ['Review Reconciliations', 'financials.reconciliation.review', 'Review and close a prepared reconciliation run'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$name, $slug, $description]) {
            Permission::updateOrCreate(['slug' => $slug], compact('name', 'description'));
        }

        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        Role::query()->where('slug', 'super-admin')->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($ids->all()));

        $this->addIndex('payment_transactions', ['status', 'received_at', 'currency'], 'pt_status_received_currency');
        $this->addIndex('payment_allocations', ['allocated_at'], 'payment_allocations_allocated_at');
        $this->addIndex('customer_credit_ledger_entries', ['created_at', 'entry_type'], 'credit_created_type');
        $this->addIndex('refund_requests', ['confirmed_at'], 'refund_confirmed_at');

        if (! Schema::hasTable('reconciliation_runs')) {
            Schema::create('reconciliation_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('reference', 60)->unique();
                $table->string('source', 40)->default('internal_receipts');
                $table->date('period_start');
                $table->date('period_end');
                $table->string('status', 30)->default('awaiting_review');
                $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('prepared_by_name_snapshot', 255)->nullable();
                $table->timestamp('prepared_at');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reviewed_by_name_snapshot', 255)->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedInteger('exception_count')->default(0);
                $table->text('resolution_notes')->nullable();
                $table->json('summary');
                $table->timestamps();
                $table->index(['source', 'period_start', 'period_end'], 'reconciliation_runs_period');
                $table->index(['status', 'prepared_at'], 'reconciliation_runs_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');

        foreach ([
            ['payment_transactions', 'pt_status_received_currency'],
            ['payment_allocations', 'payment_allocations_allocated_at'],
            ['customer_credit_ledger_entries', 'credit_created_type'],
            ['refund_requests', 'refund_confirmed_at'],
        ] as [$table, $index]) {
            $names = collect(Schema::getIndexes($table))->pluck('name')->all();
            if (in_array($index, $names, true)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
            }
        }

        $ids = Permission::query()->whereIn('slug', array_column(self::PERMISSIONS, 1))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $names = collect(Schema::getIndexes($table))->pluck('name')->all();
        if (in_array($name, $names, true)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }
};
