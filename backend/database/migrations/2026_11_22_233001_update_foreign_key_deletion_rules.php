<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enforce RESTRICT deletion rules to prevent accidental cascade deletion
     * of historical orders, line item snapshots, audit logs, and table credentials.
     */
    public function up(): void
    {
        Schema::table('table_qr_codes', function (Blueprint $table) {
            $table->dropForeign(['restaurant_table_id']);
            $table->foreign('restaurant_table_id')
                ->references('id')
                ->on('restaurant_tables')
                ->restrictOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete();
        });

        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->cascadeOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->cascadeOnDelete();
        });

        Schema::table('table_qr_codes', function (Blueprint $table) {
            $table->dropForeign(['restaurant_table_id']);
            $table->foreign('restaurant_table_id')
                ->references('id')
                ->on('restaurant_tables')
                ->cascadeOnDelete();
        });
    }
};
