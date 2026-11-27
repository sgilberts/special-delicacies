<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORIGINAL_SHAPE = "((entry_type = 'designation' AND source_payment_transaction_id IS NOT NULL AND commercial_document_id IS NULL) OR (entry_type = 'application' AND source_payment_transaction_id IS NULL AND commercial_document_id IS NOT NULL))";

    private const EXTENDED_SHAPE = "(
        (entry_type = 'designation' AND source_payment_transaction_id IS NOT NULL AND commercial_document_id IS NULL AND reverses_entry_id IS NULL)
        OR (entry_type = 'application' AND source_payment_transaction_id IS NULL AND commercial_document_id IS NOT NULL AND reverses_entry_id IS NULL)
        OR (entry_type = 'credit_note_credit' AND source_payment_transaction_id IS NULL AND commercial_document_id IS NOT NULL AND reverses_entry_id IS NULL)
        OR (entry_type = 'reversal' AND reverses_entry_id IS NOT NULL AND amount > 0)
    )";

    public function up(): void
    {
        Schema::table('customer_credit_ledger_entries', function (Blueprint $table): void {
            $table->foreignId('reverses_entry_id')->nullable()->after('commercial_document_id')
                ->constrained('customer_credit_ledger_entries')->restrictOnDelete();
            $table->foreignId('authorized_by_user_id')->nullable()->after('actor_reference_snapshot')
                ->constrained('users')->nullOnDelete();
            $table->string('authorized_by_name_snapshot', 255)->nullable()->after('authorized_by_user_id');
            $table->timestamp('authorized_at')->nullable()->after('authorized_by_name_snapshot');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE customer_credit_ledger_entries DROP CHECK credit_ledger_entry_shape');
            DB::statement('ALTER TABLE customer_credit_ledger_entries ADD CONSTRAINT credit_ledger_entry_shape CHECK '.self::EXTENDED_SHAPE);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE customer_credit_ledger_entries DROP CHECK credit_ledger_entry_shape');
            DB::statement('ALTER TABLE customer_credit_ledger_entries ADD CONSTRAINT credit_ledger_entry_shape CHECK '.self::ORIGINAL_SHAPE);
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('customer_credit_ledger_entries', function (Blueprint $table): void {
                $table->dropForeign(['reverses_entry_id']);
                $table->dropForeign(['authorized_by_user_id']);
            });
        }

        Schema::table('customer_credit_ledger_entries', function (Blueprint $table): void {
            $table->dropColumn(['reverses_entry_id', 'authorized_by_user_id', 'authorized_by_name_snapshot', 'authorized_at']);
        });
    }
};
