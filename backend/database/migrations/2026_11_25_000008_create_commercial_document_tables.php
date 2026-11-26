<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 40)->nullable()->unique();
            $table->string('document_type', 20);
            $table->string('status', 20)->default('draft');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained('organisations')->restrictOnDelete();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->string('discount_description', 255)->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'document_type', 'status', 'created_at'], 'commercial_documents_customer_list');
            $table->index(['organisation_id', 'document_type', 'status', 'created_at'], 'commercial_documents_org_list');
            $table->index(['document_type', 'status', 'issue_date'], 'commercial_documents_admin_list');
        });

        Schema::create('commercial_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_document_id')->constrained('commercial_documents')->restrictOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->nullOnDelete();
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
            $table->index(['commercial_document_id', 'position'], 'commercial_document_lines_order');
        });

        Schema::create('commercial_document_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_document_id')->constrained('commercial_documents')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['commercial_document_id', 'created_at'], 'commercial_document_history_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_document_histories');
        Schema::dropIfExists('commercial_document_lines');
        Schema::dropIfExists('commercial_documents');
    }
};
