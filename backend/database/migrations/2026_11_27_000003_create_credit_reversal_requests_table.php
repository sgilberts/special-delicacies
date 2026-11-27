<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_reversal_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_reference', 100)->unique();
            $table->foreignId('ledger_entry_id')->constrained('customer_credit_ledger_entries')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained('organisations')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->text('reason');
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('requested_by_id_snapshot')->nullable();
            $table->string('requested_by_name_snapshot', 255)->nullable();
            $table->string('requested_by_reference_snapshot', 100)->nullable();
            $table->timestamp('requested_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reversal_ledger_entry_id')->nullable()->constrained('customer_credit_ledger_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['ledger_entry_id', 'status'], 'reversal_entry_status');
            $table->index(['status', 'requested_at'], 'reversal_status_time');
            $table->index(['customer_id', 'created_at'], 'reversal_customer_history');
            $table->index(['organisation_id', 'created_at'], 'reversal_org_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_reversal_requests');
    }
};
