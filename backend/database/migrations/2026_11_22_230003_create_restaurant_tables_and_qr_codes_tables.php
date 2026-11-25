<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->string('table_number', 20)->unique();
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'table_number']);
        });

        Schema::create('table_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_table_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_qr_codes');
        Schema::dropIfExists('restaurant_tables');
    }
};
