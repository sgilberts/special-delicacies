<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            $table->enum('order_type', ['dine_in', 'delivery']);
            $table->foreignId('restaurant_table_id')->nullable()->constrained('restaurant_tables')->restrictOnDelete();
            $table->foreignId('delivery_zone_id')->nullable()->constrained('delivery_zones')->restrictOnDelete();
            $table->string('customer_name', 100);
            $table->string('customer_phone', 30);
            $table->text('delivery_address')->nullable();
            $table->text('customer_notes')->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('delivery_fee', 10, 2)->default(0.00);
            $table->decimal('total', 10, 2);
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('kitchen_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delivery_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_type', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->nullOnDelete();
            $table->string('item_name', 150);
            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->text('special_instructions')->nullable();
            $table->decimal('line_total', 10, 2);
            $table->timestamps();

            $table->index('order_id');
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
