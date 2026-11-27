<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('credit_note_number', 40)->nullable()->unique();
            $table->string('status', 24)->default('draft');
            $table->foreignId('customer_id')->constrained('customers', 'id', 'credit_note_customer_fk')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained('organisations', 'id', 'credit_note_organisation_fk')->restrictOnDelete();
            $table->foreignId('commercial_document_id')->constrained('commercial_documents', 'id', 'credit_note_source_document_fk')->restrictOnDelete();
            $table->string('original_document_number', 40);
            $table->date('original_document_date')->nullable();
            $table->date('issue_date')->nullable();
            $table->char('currency', 3);
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->string('discount_description', 255)->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'credit_note_creator_fk')->nullOnDelete();
            $table->string('created_by_name_snapshot', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'credit_note_updater_fk')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users', 'id', 'credit_note_submitter_fk')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users', 'id', 'credit_note_approver_fk')->nullOnDelete();
            $table->string('approved_by_name_snapshot', 255)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users', 'id', 'credit_note_issuer_fk')->nullOnDelete();
            $table->string('issued_by_name_snapshot', 255)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users', 'id', 'credit_note_canceller_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'credit_notes_status_created');
            $table->index(['customer_id', 'status', 'created_at'], 'credit_notes_customer_history');
            $table->index(['organisation_id', 'status', 'created_at'], 'credit_notes_organisation_history');
            $table->index(['commercial_document_id', 'status'], 'credit_notes_document_history');
        });

        Schema::create('credit_note_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes', 'id', 'credit_note_line_note_fk')->restrictOnDelete();
            $table->foreignId('commercial_document_line_id')->nullable()->constrained('commercial_document_lines', 'id', 'credit_note_line_source_fk')->restrictOnDelete();
            $table->string('description', 255);
            $table->text('detail')->nullable();
            $table->string('unit_of_measure', 40)->default('item');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('tax_rate', 7, 4)->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['credit_note_id', 'position'], 'credit_note_lines_order');
        });

        Schema::create('credit_note_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes', 'id', 'credit_application_note_fk')->restrictOnDelete();
            $table->foreignId('commercial_document_id')->constrained('commercial_documents', 'id', 'credit_application_document_fk')->restrictOnDelete();
            $table->string('operation_reference', 100)->unique();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->foreignId('applied_by')->constrained('users', 'id', 'credit_application_actor_fk')->restrictOnDelete();
            $table->unsignedBigInteger('applied_by_snapshot');
            $table->string('applied_by_name_snapshot', 255);
            $table->string('applied_by_reference_snapshot', 100);
            $table->decimal('invoice_outstanding_before', 14, 2);
            $table->decimal('invoice_outstanding_after', 14, 2);
            $table->decimal('credit_note_applied_before', 14, 2);
            $table->decimal('credit_note_applied_after', 14, 2);
            $table->timestamp('applied_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['credit_note_id', 'applied_at'], 'credit_application_note_history');
            $table->index(['commercial_document_id', 'applied_at'], 'credit_application_document_history');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_single_payer CHECK ((organisation_id IS NULL) OR (customer_id IS NOT NULL))');
            DB::statement('ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_total_non_negative CHECK (total >= 0)');
            DB::statement('ALTER TABLE credit_note_applications ADD CONSTRAINT credit_applications_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_applications');
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
    }
};
