<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('entity_type', 120)->nullable();
            $table->string('entity_id', 80)->nullable();
            $table->json('changed_fields')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_audit_logs');
    }
};
