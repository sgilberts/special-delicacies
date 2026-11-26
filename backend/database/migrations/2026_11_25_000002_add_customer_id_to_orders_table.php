<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained('customers')->restrictOnDelete();
            $table->index(['customer_id', 'created_at'], 'orders_customer_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_customer_created_index');
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
