<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantTable;
use App\Models\TableQrCode;
use App\Models\User;
use App\Services\QrCodeService;
use Database\Seeders\RestaurantSettingSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase6DineInOrderTest extends TestCase
{
    use RefreshDatabase;

    private QrCodeService $qrService;
    private RestaurantTable $table;
    private string $validPlainToken;
    private TableQrCode $activeQr;
    private MenuCategory $category;
    private MenuItem $itemA;
    private MenuItem $itemB;
    private MenuItem $unavailableItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(RestaurantSettingSeeder::class);

        $this->qrService = new QrCodeService();

        // Active table
        $this->table = RestaurantTable::create([
            'table_number' => 'T-05',
            'capacity' => 4,
            'is_active' => true,
        ]);

        // Generate QR code
        $qrResult = $this->qrService->generateForTable($this->table);
        $this->validPlainToken = $qrResult['plain_token'];
        $this->activeQr = $qrResult['qr_code'];

        // Menu category
        $this->category = MenuCategory::create([
            'name' => 'Signature Dishes',
            'slug' => 'signature-dishes',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Menu items
        $this->itemA = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Jollof Rice with Grilled Tilapia',
            'slug' => 'jollof-rice-tilapia',
            'price' => 45.00,
            'is_available' => true,
        ]);

        $this->itemB = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Kelewele Special',
            'slug' => 'kelewele-special',
            'price' => 15.50,
            'is_available' => true,
        ]);

        $this->unavailableItem = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Seasonal Lobster',
            'slug' => 'seasonal-lobster',
            'price' => 120.00,
            'is_available' => false,
        ]);
    }

    public function test_valid_qr_token_resolves_table(): void
    {
        $response = $this->getJson("/api/v1/table-lookup/{$this->validPlainToken}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.table_number', 'T-05')
            ->assertJsonPath('data.capacity', 4);
    }

    public function test_invalid_qr_token_is_rejected(): void
    {
        $fakeToken = str_repeat('b', 64);
        $response = $this->getJson("/api/v1/table-lookup/{$fakeToken}");

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_revoked_qr_token_is_rejected(): void
    {
        $this->qrService->revoke($this->activeQr);

        $response = $this->getJson("/api/v1/table-lookup/{$this->validPlainToken}");

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_inactive_table_qr_token_is_rejected(): void
    {
        $this->table->update(['is_active' => false]);

        $response = $this->getJson("/api/v1/table-lookup/{$this->validPlainToken}");

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_customer_can_submit_valid_dine_in_order_with_qr_token(): void
    {
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'customer_name' => 'Kwame Mensah',
            'customer_phone' => '+233201234567',
            'items' => [
                [
                    'menu_item_id' => $this->itemA->id,
                    'quantity' => 2,
                    'special_instructions' => 'Mild pepper please',
                ],
                [
                    'menu_item_id' => $this->itemB->id,
                    'quantity' => 1,
                    'special_instructions' => null,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_type', Order::TYPE_DINE_IN)
            ->assertJsonPath('data.restaurant_table_id', $this->table->id)
            ->assertJsonPath('data.status', Order::STATUS_PENDING)
            ->assertJsonPath('data.subtotal', '105.50') // (45 * 2) + 15.50 = 105.50
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.total', '105.50');

        $this->assertDatabaseHas('orders', [
            'restaurant_table_id' => $this->table->id,
            'order_type' => Order::TYPE_DINE_IN,
            'subtotal' => '105.50',
            'total' => '105.50',
            'status' => Order::STATUS_PENDING,
        ]);
    }

    public function test_server_calculates_correct_totals_and_ignores_client_prices(): void
    {
        // Client attempts to pass fraudulent prices ($1.00 each)
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'items' => [
                [
                    'menu_item_id' => $this->itemA->id,
                    'quantity' => 2,
                    'price' => '1.00', // Client-injected price
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', '90.00') // 45.00 * 2 = 90.00
            ->assertJsonPath('data.total', '90.00');

        $orderId = $response->json('data.id');
        $item = OrderItem::where('order_id', $orderId)->first();
        $this->assertEquals('45.00', $item->unit_price);
        $this->assertEquals('90.00', $item->line_total);
    }

    public function test_menu_item_snapshots_and_special_instructions_are_stored(): void
    {
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'items' => [
                [
                    'menu_item_id' => $this->itemA->id,
                    'quantity' => 1,
                    'special_instructions' => 'Extra grilled onions',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/orders', $payload);
        $response->assertCreated();

        $orderId = $response->json('data.id');
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'menu_item_id' => $this->itemA->id,
            'item_name' => 'Jollof Rice with Grilled Tilapia',
            'unit_price' => '45.00',
            'quantity' => 1,
            'special_instructions' => 'Extra grilled onions',
            'line_total' => '45.00',
        ]);
    }

    public function test_initial_order_status_and_history_are_correct(): void
    {
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'items' => [
                ['menu_item_id' => $this->itemB->id, 'quantity' => 1],
            ],
        ];

        $response = $this->postJson('/api/v1/orders', $payload);
        $response->assertCreated();

        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => Order::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $orderId,
            'previous_status' => null,
            'new_status' => Order::STATUS_PENDING,
        ]);
    }

    public function test_unavailable_menu_item_is_rejected(): void
    {
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'items' => [
                ['menu_item_id' => $this->unavailableItem->id, 'quantity' => 1],
            ],
        ];

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('unavailable', $response->json('message'));
    }

    public function test_invalid_quantities_are_rejected(): void
    {
        $payloadZero = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'items' => [
                ['menu_item_id' => $this->itemA->id, 'quantity' => 0],
            ],
        ];

        $this->postJson('/api/v1/orders', $payloadZero)->assertStatus(422);

        $payloadNegative = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'items' => [
                ['menu_item_id' => $this->itemA->id, 'quantity' => -2],
            ],
        ];

        $this->postJson('/api/v1/orders', $payloadNegative)->assertStatus(422);
    }

    public function test_empty_items_order_is_rejected(): void
    {
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'items' => [],
        ];

        $response = $this->postJson('/api/v1/orders', $payload);
        $response->assertStatus(422);
    }

    public function test_order_submission_with_invalid_qr_token_is_rejected(): void
    {
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => str_repeat('c', 64),
            'items' => [
                ['menu_item_id' => $this->itemA->id, 'quantity' => 1],
            ],
        ];

        $response = $this->postJson('/api/v1/orders', $payload);
        $response->assertStatus(422);
    }

    public function test_order_submission_with_revoked_qr_token_is_rejected(): void
    {
        $this->qrService->revoke($this->activeQr);

        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'items' => [
                ['menu_item_id' => $this->itemA->id, 'quantity' => 1],
            ],
        ];

        $response = $this->postJson('/api/v1/orders', $payload);
        $response->assertStatus(422);
    }

    public function test_idempotency_prevents_duplicate_orders(): void
    {
        $idempotencyKey = 'sd-idemp-' . bin2hex(random_bytes(16));

        $payload = [
            'idempotency_key' => $idempotencyKey,
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'customer_name' => 'Idempotent Customer',
            'customer_phone' => '1234567890',
            'items' => [
                ['menu_item_id' => $this->itemA->id, 'quantity' => 1],
            ],
        ];

        // First attempt -> created (201)
        $res1 = $this->postJson('/api/v1/orders', $payload);
        $res1->assertCreated();
        $orderId = $res1->json('data.id');

        // Second attempt with same key -> idempotent return (200) with identical order ID
        $res2 = $this->postJson('/api/v1/orders', $payload);
        $res2->assertOk();
        $this->assertEquals($orderId, $res2->json('data.id'));

        // Assert only ONE order was inserted in the database
        $this->assertEquals(1, Order::where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_failed_transaction_leaves_no_partial_records(): void
    {
        $initialOrderCount = Order::count();
        $initialItemCount = OrderItem::count();

        // Pass invalid menu item id within items array
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'qr_token' => $this->validPlainToken,
            'items' => [
                ['menu_item_id' => $this->itemA->id, 'quantity' => 1],
                ['menu_item_id' => 999999, 'quantity' => 1], // Nonexistent item
            ],
        ];

        $response = $this->postJson('/api/v1/orders', $payload);
        $response->assertStatus(422);

        // Ensure database state is unchanged
        $this->assertEquals($initialOrderCount, Order::count());
        $this->assertEquals($initialItemCount, OrderItem::count());
    }
}
