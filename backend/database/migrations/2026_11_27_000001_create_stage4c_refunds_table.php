<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('refund_reference', 40)->nullable()->unique();
            $table->string('status', 20)->default('draft');
            $table->string('refund_method', 30);
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions', 'id', 'refund_payment_fk')->restrictOnDelete();
            $table->foreignId('payment_allocation_id')->nullable()->constrained('payment_allocations', 'id', 'refund_allocation_fk')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders', 'id', 'refund_order_fk')->restrictOnDelete();
            $table->foreignId('commercial_document_id')->nullable()->constrained('commercial_documents', 'id', 'refund_document_fk')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers', 'id', 'refund_customer_fk')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained('organisations', 'id', 'refund_organisation_fk')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->string('operation_reference', 100)->unique();
            $table->foreignId('initiated_by')->constrained('users', 'id', 'refund_initiator_fk')->restrictOnDelete();
            $table->unsignedBigInteger('initiated_by_snapshot');
            $table->string('initiated_by_name_snapshot', 255);
            $table->string('initiated_by_reference_snapshot', 100);
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'id', 'refund_reviewer_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users', 'id', 'refund_approver_fk')->nullOnDelete();
            $table->string('approved_by_name_snapshot', 255)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users', 'id', 'refund_rejector_fk')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users', 'id', 'refund_canceller_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users', 'id', 'refund_reverser_fk')->nullOnDelete();
            $table->string('gateway_reference', 150)->nullable()->unique();
            $table->string('gateway_status', 40)->nullable();
            $table->string('external_transaction_reference', 150)->nullable();
            $table->string('gateway_idempotency_key', 100)->nullable()->unique();
            $table->foreignId('credit_ledger_entry_id')->nullable()->constrained('customer_credit_ledger_entries', 'id', 'refund_credit_entry_fk')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'refunds_status_created');
            $table->index(['customer_id', 'status', 'created_at'], 'refunds_customer_history');
            $table->index(['organisation_id', 'status', 'created_at'], 'refunds_organisation_history');
            $table->index(['payment_transaction_id', 'status'], 'refunds_payment_history');
            $table->index(['commercial_document_id', 'status'], 'refunds_document_history');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_single_payer CHECK ((customer_id IS NULL) <> (organisation_id IS NULL))');
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
