<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipt_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->string('intent', 20); // deposit or advance
            $table->string('operation_reference', 100)->unique();
            $table->text('reason');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('actor_user_id_snapshot');
            $table->string('actor_name_snapshot', 255);
            $table->string('actor_reference_snapshot', 100);
            $table->timestamp('designated_at');
            $table->timestamps();
            $table->index(['intent', 'designated_at']);
        });

        Schema::create('customer_credit_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_type', 24); // designation or application
            $table->string('operation_reference', 100)->unique();
            $table->foreignId('source_payment_transaction_id')->nullable()->constrained('payment_transactions', 'id', 'credit_source_payment_fk')->restrictOnDelete();
            $table->foreignId('commercial_document_id')->nullable()->constrained('commercial_documents', 'id', 'credit_commercial_doc_fk')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers', 'id', 'credit_customer_fk')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained('organisations', 'id', 'credit_organisation_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users', 'id', 'credit_actor_user_fk')->restrictOnDelete();
            $table->unsignedBigInteger('actor_user_id_snapshot');
            $table->string('actor_name_snapshot', 255);
            $table->string('actor_reference_snapshot', 100);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->text('reason');
            $table->decimal('credit_balance_before', 14, 2);
            $table->decimal('credit_balance_after', 14, 2);
            $table->decimal('payment_unallocated_before', 14, 2)->nullable();
            $table->decimal('payment_unallocated_after', 14, 2)->nullable();
            $table->decimal('invoice_outstanding_before', 14, 2)->nullable();
            $table->decimal('invoice_outstanding_after', 14, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_id', 'currency', 'created_at'], 'credit_customer_history');
            $table->index(['organisation_id', 'currency', 'created_at'], 'credit_org_history');
            $table->index(['source_payment_transaction_id', 'entry_type'], 'credit_source_entries');
            $table->index(['commercial_document_id', 'entry_type'], 'credit_invoice_entries');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE customer_credit_ledger_entries ADD CONSTRAINT credit_ledger_single_payer CHECK ((customer_id IS NULL) <> (organisation_id IS NULL))");
            DB::statement("ALTER TABLE customer_credit_ledger_entries ADD CONSTRAINT credit_ledger_entry_shape CHECK ((entry_type = 'designation' AND source_payment_transaction_id IS NOT NULL AND commercial_document_id IS NULL) OR (entry_type = 'application' AND source_payment_transaction_id IS NULL AND commercial_document_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_ledger_entries');
        Schema::dropIfExists('payment_receipt_intents');
    }
};
