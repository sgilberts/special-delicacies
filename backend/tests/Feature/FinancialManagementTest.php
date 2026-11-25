<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
        $this->superAdmin = User::create([
            'name' => 'Finance Owner', 'email' => 'finance-owner@test.local',
            'password' => bcrypt('secret1234'), 'is_active' => true,
        ]);
        $this->superAdmin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());
    }

    public function test_snapshot_aggregates_only_successful_receipts_and_excludes_pending_amounts(): void
    {
        $paid = $this->order(['order_number' => 'FIN-PAID', 'payment_status' => 'paid']);
        $pending = $this->order(['order_number' => 'FIN-PENDING', 'total' => '18.25']);
        $this->transaction($paid, ['status' => 'succeeded', 'received_at' => now(), 'reporting_date' => now()->toDateString()]);
        $this->transaction($pending, ['status' => 'pending', 'reporting_date' => now()->toDateString()]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')->getJson('/api/v1/admin/financials/snapshot');

        $response->assertOk()->assertJsonPath('data.currency', 'GHS');
        $today = collect($response->json('data.daily'))->firstWhere('date', now()->toDateString());
        $this->assertSame('50.00', $today['collected']);
        $this->assertSame('18.25', $today['outstanding']);
        $this->assertSame(1, $today['paid_orders']);
    }

    public function test_cash_confirmation_is_idempotent_and_creates_a_single_collected_transaction(): void
    {
        $order = $this->order(['order_number' => 'FIN-CASH']);

        app(OrderService::class)->confirmPayment($order, $this->superAdmin, 'Till receipt received');
        app(OrderService::class)->confirmPayment($order->fresh(), $this->superAdmin, 'Duplicate request');

        $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->count());
        $this->assertSame('succeeded', PaymentTransaction::where('order_id', $order->id)->value('status'));
        $this->assertSame($this->superAdmin->id, PaymentTransaction::where('order_id', $order->id)->value('confirmed_by'));
    }

    public function test_cash_confirmation_rejects_gateway_orders(): void
    {
        $order = $this->order(['order_number' => 'FIN-ONLINE', 'payment_method' => 'gateway']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only a cash payment can be confirmed as cash collected.');
        app(OrderService::class)->confirmPayment($order, $this->superAdmin);
    }

    public function test_operational_role_cannot_view_financial_snapshot_but_can_use_order_operations(): void
    {
        $waiter = User::create(['name' => 'Waiter', 'email' => 'finance-waiter@test.local', 'password' => bcrypt('secret1234'), 'is_active' => true]);
        $waiter->roles()->attach(Role::where('slug', 'waiter-waitress')->firstOrFail());

        $this->actingAs($waiter, 'sanctum')->getJson('/api/v1/admin/financials/snapshot')->assertForbidden();
        $this->actingAs($waiter, 'sanctum')->getJson('/api/v1/admin/dashboard/stats')->assertOk()->assertJsonPath('data.financials', null);
        $order = $this->order(['order_number' => 'FIN-REDACTED']);
        $this->actingAs($waiter, 'sanctum')->getJson('/api/v1/admin/orders/'.$order->id)
            ->assertOk()->assertJsonMissingPath('data.total')->assertJsonMissingPath('data.payment_details.amount');
    }

    public function test_transaction_register_supports_filters_and_csv_export(): void
    {
        $order = $this->order(['order_number' => 'FIN-EXPORT']);
        $this->transaction($order, ['status' => 'succeeded', 'received_at' => now(), 'reporting_date' => now()->toDateString()]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/financials/transactions?status=succeeded&from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.order_number', 'FIN-EXPORT');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->get('/api/v1/admin/financials/export.csv?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    private function order(array $overrides = []): Order
    {
        static $number = 0;
        $number++;
        return Order::create(array_merge([
            'order_number' => 'FIN-ORDER-'.$number,
            'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'Finance Customer',
            'customer_phone' => '0200000000',
            'status' => Order::STATUS_COMPLETED,
            'subtotal' => '50.00', 'delivery_fee' => '0.00', 'total' => '50.00',
            'payment_method' => Order::PAYMENT_METHOD_CASH,
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
        ], $overrides));
    }

    private function transaction(Order $order, array $overrides = []): PaymentTransaction
    {
        static $number = 0;
        $number++;
        return PaymentTransaction::create(array_merge([
            'order_id' => $order->id,
            'transaction_reference' => 'FIN-REF-'.$number,
            'payment_method' => 'cash', 'amount' => $order->total, 'currency' => 'GHS',
            'status' => 'pending', 'reporting_date' => now()->toDateString(),
        ], $overrides));
    }
}
