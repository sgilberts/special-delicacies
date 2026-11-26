<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('grand_total', 10, 2)->nullable()->after('total');
            $table->string('delivery_fee_payment_method', 32)->nullable()->after('grand_total');
            $table->string('delivery_fee_payment_status', 32)->default('not_applicable')->after('delivery_fee_payment_method');
            $table->timestamp('delivery_fee_confirmed_at')->nullable()->after('delivery_fee_payment_status');
            $table->foreignId('delivery_fee_confirmed_by')->nullable()->after('delivery_fee_confirmed_at')->constrained('users')->nullOnDelete();
        });

        DB::table('orders')->whereNull('grand_total')->update(['grand_total' => DB::raw('total')]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY order_type ENUM('dine_in', 'delivery', 'pickup') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::table('orders')->where('order_type', 'pickup')->exists()) {
            throw new RuntimeException('Cannot roll back pickup support while pickup orders exist.');
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY order_type ENUM('dine_in', 'delivery') NOT NULL");
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_fee_confirmed_by');
            $table->dropColumn(['delivery_fee_confirmed_at', 'delivery_fee_payment_status', 'delivery_fee_payment_method', 'grand_total']);
        });
    }
};
