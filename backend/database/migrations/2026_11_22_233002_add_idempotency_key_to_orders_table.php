<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add idempotency_key to orders table to prevent duplicate submissions
     * from double-clicking, repeated HTTP requests, or network retries.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('order_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key']);
        });
    }
};
