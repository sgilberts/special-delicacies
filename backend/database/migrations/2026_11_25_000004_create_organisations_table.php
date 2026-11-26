<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('owner_customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('business_email')->nullable();
            $table->string('business_phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->index(['owner_customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
};
