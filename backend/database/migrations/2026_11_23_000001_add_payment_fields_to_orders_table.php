<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 30)->default('cash')->after('total');
            $table->string('payment_status', 30)->default('pending')->after('payment_method');
            $table->index('payment_status', 'orders_payment_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_payment_status_index');
            $table->dropColumn(['payment_method', 'payment_status']);
        });
    }
};
