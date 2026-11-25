<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->after('customer_phone');
            $table->string('payment_reference')->nullable()->unique()->after('payment_status');
            $table->text('gateway_checkout_url')->nullable()->after('payment_reference');
        });

        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->unique();
            $table->string('display_name', 100);
            $table->string('public_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['payment_reference']);
            $table->dropColumn(['customer_email', 'payment_reference', 'gateway_checkout_url']);
        });
    }
};
