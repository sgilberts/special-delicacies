<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            // Existing rows and ordinary checkout remain order-purpose by default.
            // Only explicitly created invoice-purpose receipts may be unlinked.
            $table->foreignId('order_id')->nullable()->change();
            $table->string('payment_purpose', 32)->default('order')->after('transaction_reference')->index();
            $table->foreignId('customer_id')->nullable()->after('order_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->after('customer_id')->constrained('organisations')->restrictOnDelete();
            $table->index(['payment_purpose', 'status', 'received_at'], 'payment_transactions_purpose_eligibility');
            $table->index(['customer_id', 'payment_purpose'], 'payment_transactions_customer_purpose');
            $table->index(['organisation_id', 'payment_purpose'], 'payment_transactions_org_purpose');
        });

        Schema::create('payment_allocation_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions')->restrictOnDelete();
            $table->string('operation_reference', 100)->unique();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('payment_unallocated_before', 14, 2);
            $table->decimal('payment_unallocated_after', 14, 2);
            $table->text('reason')->nullable();
            $table->timestamp('allocated_at');
            $table->timestamps();
            $table->index(['payment_transaction_id', 'allocated_at'], 'payment_allocation_operations_payment_date');
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_allocation_operation_id')->constrained('payment_allocation_operations')->restrictOnDelete();
            $table->foreignId('commercial_document_id')->constrained('commercial_documents')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->decimal('invoice_balance_before', 14, 2);
            $table->decimal('invoice_balance_after', 14, 2);
            $table->timestamp('allocated_at');
            $table->timestamps();
            $table->unique(['payment_allocation_operation_id', 'commercial_document_id'], 'payment_allocation_operation_document_unique');
            $table->index(['commercial_document_id', 'allocated_at'], 'payment_allocations_document_date');
        });

        Schema::create('payment_allocation_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_allocation_id')->unique()->constrained('payment_allocations')->restrictOnDelete();
            $table->string('operation_reference', 100)->unique();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->decimal('payment_unallocated_before', 14, 2);
            $table->decimal('payment_unallocated_after', 14, 2);
            $table->decimal('invoice_balance_before', 14, 2);
            $table->decimal('invoice_balance_after', 14, 2);
            $table->timestamp('reversed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocation_reversals');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_allocation_operations');
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropIndex('payment_transactions_purpose_eligibility');
            $table->dropIndex('payment_transactions_customer_purpose');
            $table->dropIndex('payment_transactions_org_purpose');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('organisation_id');
            $table->dropColumn('payment_purpose');
            $table->foreignId('order_id')->nullable(false)->change();
        });
    }
};
