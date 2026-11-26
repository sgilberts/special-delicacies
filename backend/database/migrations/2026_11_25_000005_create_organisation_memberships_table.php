<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained('organisations')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('role', 30)->default('member');
            $table->string('status', 20)->default('pending');
            $table->foreignId('invited_by')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'customer_id'], 'organisation_memberships_org_customer_unique');
            $table->index(['organisation_id', 'status', 'role'], 'organisation_memberships_scope_index');
            $table->index(['customer_id', 'status'], 'organisation_memberships_customer_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_memberships');
    }
};
