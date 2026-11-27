<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stage 4C extends the existing immutable Stage 4B ledger with one new
        // entry type. No existing entry is rewritten and no second ledger is
        // introduced; a refund credit is traceable back to its refund record.
        Schema::table('customer_credit_ledger_entries', function (Blueprint $table): void {
            $table->foreignId('source_refund_id')->nullable()->after('source_payment_transaction_id')
                ->constrained('refunds', 'id', 'credit_source_refund_fk')->restrictOnDelete();
            $table->unique('source_refund_id', 'credit_source_refund_unique');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE customer_credit_ledger_entries DROP CHECK credit_ledger_entry_shape');
            DB::statement("ALTER TABLE customer_credit_ledger_entries ADD CONSTRAINT credit_ledger_entry_shape CHECK ((entry_type = 'designation' AND source_payment_transaction_id IS NOT NULL AND commercial_document_id IS NULL AND source_refund_id IS NULL) OR (entry_type = 'application' AND source_payment_transaction_id IS NULL AND commercial_document_id IS NOT NULL AND source_refund_id IS NULL) OR (entry_type = 'refund_credit' AND source_payment_transaction_id IS NOT NULL AND commercial_document_id IS NULL AND source_refund_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE customer_credit_ledger_entries DROP CHECK credit_ledger_entry_shape');
            DB::statement("ALTER TABLE customer_credit_ledger_entries ADD CONSTRAINT credit_ledger_entry_shape CHECK ((entry_type = 'designation' AND source_payment_transaction_id IS NOT NULL AND commercial_document_id IS NULL) OR (entry_type = 'application' AND source_payment_transaction_id IS NULL AND commercial_document_id IS NOT NULL))");
        }

        Schema::table('customer_credit_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('credit_source_refund_unique');
            $table->dropForeign('credit_source_refund_fk');
            $table->dropColumn('source_refund_id');
        });
    }
};
