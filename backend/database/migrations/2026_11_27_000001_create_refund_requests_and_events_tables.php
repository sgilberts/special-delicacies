<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('refund_reference', 100)->unique();
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained('organisations')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('requested');
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('requested_by_id_snapshot')->nullable();
            $table->string('requested_by_name_snapshot', 255)->nullable();
            $table->string('requested_by_reference_snapshot', 100)->nullable();
            $table->timestamp('requested_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_reason')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_refund_reference', 150)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('verification_reference', 150)->nullable();
            $table->text('verification_evidence')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payment_transaction_id', 'status'], 'refund_payment_status');
            $table->index(['status', 'requested_at'], 'refund_status_time');
            $table->index(['customer_id', 'created_at'], 'refund_customer_history');
            $table->index(['organisation_id', 'created_at'], 'refund_org_history');
        });

        Schema::create('refund_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_request_id')->constrained('refund_requests')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('actor_user_id_snapshot')->nullable();
            $table->string('actor_name_snapshot', 255)->nullable();
            $table->string('actor_reference_snapshot', 100)->nullable();
            $table->text('reason')->nullable();
            $table->string('provider_reference', 150)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['refund_request_id', 'created_at'], 'refund_event_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_events');
        Schema::dropIfExists('refund_requests');
    }
};
