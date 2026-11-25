<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\RestaurantTable;
use App\Models\TableQrCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForeignKeyRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_delete_order_when_order_items_exist(): void
    {
        $order = Order::create([
            'order_number' => 'SD-TEST-0001',
            'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'John',
            'customer_phone' => '123456',
            'status' => Order::STATUS_PENDING,
            'subtotal' => 20.00,
            'delivery_fee' => 0.00,
            'total' => 20.00,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_name' => 'Burger',
            'unit_price' => 20.00,
            'quantity' => 1,
            'line_total' => 20.00,
        ]);

        $this->expectException(QueryException::class);
        $order->delete();
    }

    public function test_cannot_delete_order_when_status_histories_exist(): void
    {
        $order = Order::create([
            'order_number' => 'SD-TEST-0002',
            'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'Jane',
            'customer_phone' => '654321',
            'status' => Order::STATUS_PENDING,
            'subtotal' => 15.00,
            'delivery_fee' => 0.00,
            'total' => 15.00,
        ]);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'new_status' => Order::STATUS_PENDING,
        ]);

        $this->expectException(QueryException::class);
        $order->delete();
    }

    public function test_cannot_delete_restaurant_table_when_qr_codes_exist(): void
    {
        $table = RestaurantTable::create([
            'table_number' => 'T-99',
            'capacity' => 4,
            'is_active' => true,
        ]);

        TableQrCode::create([
            'restaurant_table_id' => $table->id,
            'token_hash' => hash('sha256', 'dummy_token_99'),
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);
        $table->delete();
    }

    public function test_cannot_delete_restaurant_table_when_orders_exist(): void
    {
        $table = RestaurantTable::create([
            'table_number' => 'T-98',
            'capacity' => 2,
            'is_active' => true,
        ]);

        Order::create([
            'order_number' => 'SD-TEST-0003',
            'order_type' => Order::TYPE_DINE_IN,
            'restaurant_table_id' => $table->id,
            'customer_name' => 'Customer',
            'customer_phone' => '999999',
            'status' => Order::STATUS_PENDING,
            'subtotal' => 10.00,
            'delivery_fee' => 0.00,
            'total' => 10.00,
        ]);

        $this->expectException(QueryException::class);
        $table->delete();
    }

    public function test_cannot_delete_delivery_zone_when_orders_exist(): void
    {
        $zone = DeliveryZone::create([
            'name' => 'Zone North',
            'delivery_fee' => 10.00,
            'is_active' => true,
        ]);

        Order::create([
            'order_number' => 'SD-TEST-0004',
            'order_type' => Order::TYPE_DELIVERY,
            'delivery_zone_id' => $zone->id,
            'customer_name' => 'Delivery Customer',
            'customer_phone' => '888888',
            'delivery_address' => 'Sample Address',
            'status' => Order::STATUS_PENDING,
            'subtotal' => 30.00,
            'delivery_fee' => 10.00,
            'total' => 40.00,
        ]);

        $this->expectException(QueryException::class);
        $zone->delete();
    }
}
