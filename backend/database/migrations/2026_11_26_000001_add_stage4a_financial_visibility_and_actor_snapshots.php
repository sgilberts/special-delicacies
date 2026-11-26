<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(
            ['slug' => 'financials.view'],
            ['name' => 'View Financial Dashboard', 'description' => 'View financial dashboard and aggregates'],
        );

        if (! Schema::hasTable('organisation_membership_permissions')) {
            Schema::create('organisation_membership_permissions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organisation_membership_id');
                $table->string('permission', 80);
                $table->unsignedBigInteger('granted_by_customer_id')->nullable();
                $table->timestamp('granted_at');
                $table->timestamps();
            });
        }
        $this->ensureIndex('organisation_membership_permissions', 'organisation_membership_permission_unique', ['organisation_membership_id', 'permission'], true);
        $this->ensureIndex('organisation_membership_permissions', 'organisation_permission_lookup', ['permission', 'organisation_membership_id']);
        $this->ensureForeignKey('organisation_membership_permissions', 'org_mem_perm_membership_fk', 'organisation_membership_id', 'organisation_memberships', 'restrict');
        $this->ensureForeignKey('organisation_membership_permissions', 'org_mem_perm_granted_by_fk', 'granted_by_customer_id', 'customers', 'null');

        $this->ensureActorSnapshotColumns('payment_allocation_operations');
        $this->ensureActorSnapshotColumns('payment_allocation_reversals');

        // Backfill only identity rows verifiably matched through the existing actor ID.
        DB::table('payment_allocation_operations')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                if ($row->allocated_by === null) continue;
                $name = DB::table('users')->where('id', $row->allocated_by)->value('name');
                DB::table('payment_allocation_operations')->where('id', $row->id)->update([
                    'actor_user_id_snapshot' => $row->allocated_by,
                    'actor_name_snapshot' => $name ?: 'Unknown / unavailable',
                    'actor_reference_snapshot' => 'user:'.$row->allocated_by,
                ]);
            }
        });
        DB::table('payment_allocation_reversals')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                if ($row->reversed_by === null) continue;
                $name = DB::table('users')->where('id', $row->reversed_by)->value('name');
                DB::table('payment_allocation_reversals')->where('id', $row->id)->update([
                    'actor_user_id_snapshot' => $row->reversed_by,
                    'actor_name_snapshot' => $name ?: 'Unknown / unavailable',
                    'actor_reference_snapshot' => 'user:'.$row->reversed_by,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocation_reversals', function (Blueprint $table): void {
            $table->dropColumn(['actor_user_id_snapshot', 'actor_name_snapshot', 'actor_reference_snapshot']);
        });
        Schema::table('payment_allocation_operations', function (Blueprint $table): void {
            $table->dropColumn(['actor_user_id_snapshot', 'actor_name_snapshot', 'actor_reference_snapshot']);
        });
        Schema::dropIfExists('organisation_membership_permissions');
    }

    private function ensureActorSnapshotColumns(string $tableName): void
    {
        foreach ([
            'actor_user_id_snapshot' => fn (Blueprint $table) => $table->unsignedBigInteger('actor_user_id_snapshot')->nullable(),
            'actor_name_snapshot' => fn (Blueprint $table) => $table->string('actor_name_snapshot', 255)->default('Unknown / unavailable'),
            'actor_reference_snapshot' => fn (Blueprint $table) => $table->string('actor_reference_snapshot', 100)->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, fn (Blueprint $table) => $definition($table));
            }
        }
    }

    private function ensureIndex(string $tableName, string $indexName, array $columns, bool $unique = false): void
    {
        $indexes = Schema::getIndexes($tableName);
        if (collect($indexes)->contains(fn (array $index): bool => $index['name'] === $indexName)) return;
        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName, $unique): void {
            if ($unique) $table->unique($columns, $indexName);
            else $table->index($columns, $indexName);
        });
    }

    private function ensureForeignKey(string $tableName, string $foreignKeyName, string $column, string $parentTable, string $deleteAction): void
    {
        $foreignKeys = Schema::getForeignKeys($tableName);
        if (collect($foreignKeys)->contains(fn (array $foreignKey): bool => $foreignKey['name'] === $foreignKeyName)) return;
        Schema::table($tableName, function (Blueprint $table) use ($foreignKeyName, $column, $parentTable, $deleteAction): void {
            $foreign = $table->foreign($column, $foreignKeyName)->references('id')->on($parentTable);
            if ($deleteAction === 'null') $foreign->nullOnDelete();
            else $foreign->restrictOnDelete();
        });
    }
};
