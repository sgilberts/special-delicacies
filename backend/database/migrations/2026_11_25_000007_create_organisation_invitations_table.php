<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained('organisations')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('invited_by')->constrained('customers')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'customer_id'], 'organisation_invitations_org_customer_unique');
            $table->index(['customer_id', 'status', 'expires_at'], 'organisation_invitations_inbox_index');
            $table->index(['organisation_id', 'status', 'expires_at'], 'organisation_invitations_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_invitations');
    }
};
