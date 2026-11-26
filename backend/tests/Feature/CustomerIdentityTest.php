<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_sign_in_read_and_update_profile_without_staff_roles(): void
    {
        $response = $this->postJson('/api/v1/customers/register', [
            'name' => 'Amina Mensah',
            'phone' => '+233 20 123 4567',
            'email' => 'AMINA@example.com',
            'password' => 'correct-horse-2026',
            'password_confirmation' => 'correct-horse-2026',
        ]);

        $response->assertCreated()->assertJsonPath('data.customer.name', 'Amina Mensah');
        $this->assertDatabaseHas('customers', ['phone' => '+233 20 123 4567', 'email' => 'amina@example.com']);
        $customer = Customer::firstOrFail();
        $this->assertFalse(method_exists($customer, 'roles'));
        $this->assertDatabaseMissing('users', ['email' => 'amina@example.com']);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'registered']);

        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/v1/customer/profile')
            ->assertOk()->assertJsonPath('data.phone', '+233 20 123 4567');
        $this->withToken($token)->patchJson('/api/v1/customer/profile', ['name' => 'Amina K. Mensah', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Amina K. Mensah');
        $this->assertTrue($customer->fresh()->is_active);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'profile_updated']);
        $login = $this->postJson('/api/v1/customers/login', ['phone' => '+233 20 123 4567', 'password' => 'correct-horse-2026']);
        $login->assertOk()->assertJsonStructure(['data' => ['token', 'customer']]);
    }

    public function test_customer_routes_reject_guests_and_staff_tokens(): void
    {
        $this->getJson('/api/v1/customer/profile')->assertUnauthorized();
        $customer = Customer::create(['name' => 'Customer', 'phone' => '0200011111', 'password' => 'correct-horse-2026', 'is_active' => true]);
        $customerToken = $customer->createToken('customer-isolation')->plainTextToken;
        $this->withToken($customerToken)->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->withToken($customerToken)->getJson('/api/v1/auth/me')->assertForbidden();
        $this->withToken($customerToken)->getJson('/api/v1/user')->assertForbidden();

    }

    public function test_staff_tokens_are_rejected_by_customer_routes(): void
    {
        $staff = User::factory()->create();
        $token = $staff->createToken('staff-test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/customer/profile')->assertForbidden();
    }

    public function test_account_registration_rejects_duplicate_phone_and_invalid_data(): void
    {
        Customer::create(['name' => 'Existing Customer', 'phone' => '0200000000', 'password' => 'correct-horse-2026', 'is_active' => true]);

        $this->postJson('/api/v1/customers/register', [
            'name' => 'X', 'phone' => '0200000000', 'email' => 'bad',
            'password' => 'short', 'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone', 'email', 'password']);
    }

    public function test_deactivated_customer_cannot_use_an_existing_access_token(): void
    {
        $customer = Customer::create(['name' => 'Inactive', 'phone' => '0200099999', 'password' => 'correct-horse-2026', 'is_active' => false]);
        $token = $customer->createToken('inactive-test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/customer/profile')->assertForbidden();
    }

    public function test_customer_sees_only_owned_orders_and_authenticated_order_creation_is_linked(): void
    {
        $customer = Customer::create(['name' => 'Amina Mensah', 'phone' => '0201234567', 'password' => 'correct-horse-2026', 'is_active' => true]);
        $other = Customer::create(['name' => 'Other Customer', 'phone' => '0207654321', 'password' => 'correct-horse-2026', 'is_active' => true]);
        $category = MenuCategory::create(['name' => 'Mains', 'slug' => 'customer-mains']);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'Jollof Rice', 'slug' => 'customer-jollof', 'price' => 20, 'is_available' => true]);
        $table = RestaurantTable::create(['table_number' => 'C-01', 'capacity' => 2, 'is_active' => true]);

        $response = $this->withToken($customer->createToken('customer-test')->plainTextToken)
            ->postJson('/api/v1/orders', [
                'order_type' => 'dine_in', 'restaurant_table_id' => $table->id,
                'customer_name' => 'Amina Snapshot', 'customer_phone' => '0201234567',
                'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ]);

        $response->assertCreated()->assertJsonPath('data.customer_name', 'Amina Snapshot');
        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('Amina Snapshot', $order->customer_name);
        $this->withToken($customer->createToken('customer-orders-own')->plainTextToken)
            ->getJson('/api/v1/customer/orders')->assertOk()->assertJsonPath('data.0.order_number', $order->order_number);

        $order->update(['customer_id' => $other->id]);
        $this->withToken($customer->createToken('customer-orders')->plainTextToken)
            ->getJson('/api/v1/customer/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/customer/orders')
            ->assertOk()->assertJsonPath('data.0.order_number', $order->order_number);
    }

    public function test_guest_orders_remain_unlinked_and_existing_tracking_route_is_available(): void
    {
        $customer = Customer::create(['name' => 'Not The Guest', 'phone' => '0200088888', 'password' => 'correct-horse-2026', 'is_active' => true]);
        $category = MenuCategory::create(['name' => 'Mains', 'slug' => 'guest-mains']);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'Rice', 'slug' => 'guest-rice', 'price' => 10, 'is_available' => true]);
        $table = RestaurantTable::create(['table_number' => 'G-01', 'capacity' => 2, 'is_active' => true]);

        $response = $this->postJson('/api/v1/orders', [
            'order_type' => 'dine_in', 'restaurant_table_id' => $table->id,
            'customer_name' => 'Guest Snapshot', 'customer_phone' => 'N/A',
            'customer_id' => $customer->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $this->assertNull($order->customer_id);
        $this->assertSame('Guest Snapshot', $order->customer_name);
        $this->getJson('/api/v1/orders/track/'.$order->order_number)->assertOk()->assertJsonPath('data.order_number', $order->order_number);
    }
}
