<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage 4B completion: staff-recorded deposits need an evidence and
     * two-person verification workflow attached to the canonical payment
     * transaction. This is deliberately NOT a second deposit ledger: the
     * payment transaction stays the financial record and this table only
     * carries the recording/verification audit trail.
     *
     * Customer-initiated deposits have no staff user to attribute the receipt
     * classification to, so the receipt-intent actor columns become nullable.
     * Staff classifications keep writing the user actor exactly as before.
     */
    public function up(): void
    {
        Schema::create('staff_deposit_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->string('intent', 20); // deposit or advance
            $table->string('operation_reference', 100)->unique();
            $table->string('external_reference', 120);
            $table->string('payment_method', 40);
            $table->text('evidence');
            $table->string('status', 20)->default('pending'); // pending, verified, rejected
            $table->foreignId('recorded_by')->constrained('users', 'id', 'staff_deposit_recorded_by_fk')->restrictOnDelete();
            $table->unsignedBigInteger('recorded_by_snapshot');
            $table->string('recorded_by_name_snapshot', 255);
            $table->timestamp('recorded_at');
            $table->foreignId('verified_by')->nullable()->constrained('users', 'id', 'staff_deposit_verified_by_fk')->restrictOnDelete();
            $table->unsignedBigInteger('verified_by_snapshot')->nullable();
            $table->string('verified_by_name_snapshot', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->unique(['payment_method', 'external_reference'], 'staff_deposit_external_reference_unique');
            $table->index(['status', 'recorded_at'], 'staff_deposit_status_queue');
            $table->index(['recorded_by', 'recorded_at'], 'staff_deposit_recorder_history');
        });

        Schema::table('payment_receipt_intents', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable()->change();
            $table->unsignedBigInteger('actor_user_id_snapshot')->nullable()->change();
            $table->string('actor_name_snapshot', 255)->nullable()->change();
            $table->string('actor_reference_snapshot', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Re-narrowing the actor columns is only possible while every receipt
        // still has a staff actor. Check BEFORE any DDL so a blocked rollback
        // leaves the schema untouched instead of failing halfway through.
        if (DB::table('payment_receipt_intents')->whereNull('actor_user_id')->exists()) {
            throw new RuntimeException(
                'Cannot roll back staff_deposit_records: payment_receipt_intents contains customer-initiated receipts '
                .'with no staff actor, so actor_user_id cannot be made NOT NULL again. No schema change was applied.'
            );
        }

        Schema::table('payment_receipt_intents', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable(false)->change();
            $table->unsignedBigInteger('actor_user_id_snapshot')->nullable(false)->change();
            $table->string('actor_name_snapshot', 255)->nullable(false)->change();
            $table->string('actor_reference_snapshot', 100)->nullable(false)->change();
        });

        Schema::dropIfExists('staff_deposit_records');
    }
};
