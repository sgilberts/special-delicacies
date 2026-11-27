<?php

namespace Tests\Feature;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerCreditService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Stage 4B — the admin "Deposits and customer credit" page.
 *
 * The page loads four sections in parallel: eligible receipts, eligible
 * invoices, credit balances and the credit ledger, plus the staff deposit
 * queue. These tests pin the exact response contract the React page consumes,
 * the permission boundary for each section, empty states, and the server-error
 * path, so a section that breaks can no longer collapse the whole page.
 */
class Stage4bCustomerCreditPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_ENDPOINTS = [
        '/api/v1/admin/customer-credit/receipts',
        '/api/v1/admin/customer-credit/invoices',
        '/api/v1/admin/customer-credit/balances',
        '/api/v1/admin/customer-credit/ledger?per_page=50',
    ];

    private Customer $customer;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        app(RoleAndPermissionSeeder::class)->run();
        $this->customer = Customer::create([
            'name' => 'Page Credit Customer', 'phone' => '0200000901', 'email' => 'page-credit@example.test',
            'password' => 'customer-password', 'is_active' => true,
        ]);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_super_admin_loads_every_section_of_the_page(): void
    {
        $this->seedCreditScenario();
        $this->actingAs($this->superAdmin(), 'sanctum');

        $this->getJson('/api/v1/admin/customer-credit/receipts')->assertOk()
            ->assertJsonStructure(['success', 'data' => [[
                'id', 'reference', 'payer' => ['type', 'id', 'name'], 'amount', 'currency',
                'intent', 'reason', 'unallocated_amount', 'verified_at', 'received_at',
            ]], 'meta'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'PAGE-PAY-1')
            ->assertJsonPath('data.0.intent', 'advance');

        $this->getJson('/api/v1/admin/customer-credit/invoices')->assertOk()
            ->assertJsonStructure(['success', 'data' => [[
                'id', 'document_number', 'payer' => ['type', 'id', 'name'], 'currency', 'total',
                'payment_summary' => ['outstanding_balance', 'credit_applied_amount'],
            ]], 'meta'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_number', 'PAGE-INV-1')
            ->assertJsonPath('data.0.payment_summary.credit_applied_amount', '30.00')
            ->assertJsonPath('data.0.payment_summary.outstanding_balance', '170.00');

        $this->getJson('/api/v1/admin/customer-credit/balances')->assertOk()
            ->assertJsonStructure(['success', 'data' => [[
                'payer' => ['type', 'id', 'name'], 'currency', 'available_balance',
            ]]])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available_balance', '30.00');

        $this->getJson('/api/v1/admin/customer-credit/ledger?per_page=50')->assertOk()
            ->assertJsonStructure(['success', 'data' => [[
                'id', 'type', 'amount', 'currency', 'reference', 'reason',
                'source_reference', 'invoice_number', 'actor', 'created_at', 'credit_balance_after',
            ]], 'meta'])
            ->assertJsonCount(2, 'data');

        // The staff deposit queue rendered on the same page.
        $this->getJson('/api/v1/admin/deposits?per_page=25&status=pending')->assertOk()
            ->assertJsonStructure(['success', 'data', 'meta'])
            ->assertJsonCount(0, 'data');
    }

    public function test_empty_sections_return_successful_empty_payloads_not_errors(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum');

        foreach (self::PAGE_ENDPOINTS as $endpoint) {
            $response = $this->getJson($endpoint)->assertOk()->assertJsonPath('success', true);
            $this->assertSame([], $response->json('data'), "Expected an empty data array from {$endpoint}.");
        }

        $this->getJson('/api/v1/admin/deposits')->assertOk()->assertJsonPath('data', []);
    }

    public function test_deposit_viewer_reads_receipts_only(): void
    {
        $this->actingAs($this->staffWith(['financials.deposits.view']), 'sanctum');

        $this->getJson('/api/v1/admin/customer-credit/receipts')->assertOk();
        $this->getJson('/api/v1/admin/customer-credit/invoices')->assertForbidden();
        $this->getJson('/api/v1/admin/customer-credit/balances')->assertForbidden();
        $this->getJson('/api/v1/admin/customer-credit/ledger')->assertForbidden();
    }

    public function test_credit_viewer_reads_credit_sections_only(): void
    {
        $this->actingAs($this->staffWith(['financials.customer_credit.view']), 'sanctum');

        $this->getJson('/api/v1/admin/customer-credit/invoices')->assertOk();
        $this->getJson('/api/v1/admin/customer-credit/balances')->assertOk();
        $this->getJson('/api/v1/admin/customer-credit/ledger')->assertOk();
        $this->getJson('/api/v1/admin/customer-credit/receipts')->assertForbidden();
    }

    public function test_finance_staff_with_every_view_permission_loads_the_whole_page(): void
    {
        $this->seedCreditScenario();
        $this->actingAs($this->staffWith([
            'financials.deposits.view', 'financials.customer_credit.view',
        ]), 'sanctum');

        foreach (self::PAGE_ENDPOINTS as $endpoint) {
            $this->getJson($endpoint)->assertOk();
        }
    }

    public function test_staff_without_financial_permissions_is_denied_every_section(): void
    {
        $this->actingAs($this->staffWith([]), 'sanctum');

        foreach (self::PAGE_ENDPOINTS as $endpoint) {
            $this->getJson($endpoint)->assertForbidden();
        }
        $this->getJson('/api/v1/admin/deposits')->assertForbidden();
    }

    public function test_guest_and_customer_accounts_cannot_read_staff_financial_sections(): void
    {
        $this->getJson('/api/v1/admin/customer-credit/receipts')->assertUnauthorized();

        $this->customer->createToken('customer-session');
        $this->actingAs($this->customer, 'sanctum');
        $this->getJson('/api/v1/admin/customer-credit/receipts')->assertForbidden();
        $this->getJson('/api/v1/admin/customer-credit/balances')->assertForbidden();
        $this->getJson('/api/v1/admin/deposits')->assertForbidden();
    }

    public function test_customer_sees_only_credit_for_organisations_they_are_financially_authorised_for(): void
    {
        [$ownOrganisation, $otherOrganisation] = $this->twoOrganisationsWithCredit();
        $this->customer->forceFill(['email_verified_at' => now(), 'phone_verified_at' => now()])->save();
        $this->customer->createToken('customer-session');
        $this->actingAs($this->customer, 'sanctum');

        // Customer's own credit is visible; neither organisation's credit is.
        $this->getJson('/api/v1/customer/credit')->assertOk()
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.payer.type', 'customer');

        $membership = OrganisationMembership::create([
            'organisation_id' => $ownOrganisation->id, 'customer_id' => $this->customer->id,
            'role' => 'owner', 'status' => OrganisationMembership::STATUS_ACTIVE,
        ]);
        $membership->permissions()->create(['permission' => 'financials.view', 'granted_at' => now()]);

        $response = $this->getJson('/api/v1/customer/credit')->assertOk();
        $organisations = collect($response->json('data.accounts'))->where('payer.type', 'organisation')->values();
        $this->assertCount(1, $organisations);
        $this->assertSame($ownOrganisation->id, $organisations[0]['payer']['id']);
        $this->assertNotContains($otherOrganisation->id, $organisations->pluck('payer.id')->all());
    }

    public function test_a_section_failure_returns_a_safe_server_error_instead_of_financial_data(): void
    {
        config(['app.debug' => false]);
        $this->actingAs($this->superAdmin(), 'sanctum');

        $this->mock(CustomerCreditService::class, function ($mock): void {
            $mock->shouldReceive('staffBalances')->once()->andThrow(new RuntimeException('ledger connection lost: secret-internal-detail'));
            $mock->shouldReceive('eligibleReceipts')->andReturn(new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20));
        });

        $response = $this->getJson('/api/v1/admin/customer-credit/balances')->assertStatus(500);
        $this->assertStringNotContainsString('secret-internal-detail', $response->getContent());
        $this->assertStringNotContainsString('ledger connection lost', $response->getContent());
    }

    private function seedCreditScenario(): void
    {
        $invoice = $this->invoice($this->customer, '200.00', 'PAGE-INV-1');
        $receipt = $this->receipt($this->customer, '100.00', 'PAGE-PAY-1');
        $credit = app(CustomerCreditService::class);
        $credit->classifyReceipt($receipt->id, 'advance', 'page-intent-0001', 'Advance received before invoice', $this->actor);
        $credit->designateCredit($receipt->id, '60.00', 'page-credit-0001', 'Customer requested account credit', $this->actor);
        $credit->applyCredit($invoice->id, '30.00', 'page-apply-0001', 'Apply credit to issued invoice', $this->actor);
    }

    /** @return array{0: Organisation, 1: Organisation} */
    private function twoOrganisationsWithCredit(): array
    {
        $own = Organisation::create(['reference' => 'PAGE-ORG-OWN', 'owner_customer_id' => $this->customer->id, 'name' => 'Own Organisation', 'status' => 'active']);
        $other = Organisation::create(['reference' => 'PAGE-ORG-OTHER', 'owner_customer_id' => $this->customer->id, 'name' => 'Other Organisation', 'status' => 'active']);
        $credit = app(CustomerCreditService::class);

        foreach ([['own', $own], ['other', $other]] as [$key, $organisation]) {
            $receipt = $this->receipt($this->customer, '20.00', 'PAGE-ORG-PAY-'.strtoupper($key), [
                'customer_id' => null, 'organisation_id' => $organisation->id,
            ]);
            $credit->classifyReceipt($receipt->id, 'deposit', 'page-org-intent-'.$key, 'Organisation deposit', $this->actor);
            $credit->designateCredit($receipt->id, '20.00', 'page-org-credit-'.$key, 'Organisation credit', $this->actor);
        }

        return [$own, $other];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());

        return $user;
    }

    /** @param list<string> $permissions */
    private function staffWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Page Test Role '.uniqid(), 'slug' => 'page-test-role-'.uniqid(),
        ]);
        $role->permissions()->sync(
            collect($permissions)->map(fn (string $slug) => Permission::where('slug', $slug)->firstOrFail()->id)->all()
        );
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function invoice(Customer $payer, string $total, string $number, array $overrides = []): CommercialDocument
    {
        return CommercialDocument::create(array_merge([
            'document_number' => $number, 'document_type' => 'invoice', 'status' => 'issued',
            'customer_id' => $payer->id, 'currency' => 'GHS', 'subtotal' => $total,
            'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => $total,
            'issue_date' => today(), 'issued_at' => now(),
        ], $overrides));
    }

    private function receipt(Customer $payer, string $amount, string $reference, array $overrides = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'order_id' => null, 'customer_id' => $payer->id, 'organisation_id' => null, 'payment_purpose' => 'invoice',
            'transaction_reference' => $reference, 'payment_method' => 'cash', 'amount' => $amount, 'currency' => 'GHS',
            'status' => 'succeeded', 'verified_at' => now(), 'received_at' => now(), 'reporting_date' => now()->toDateString(),
        ], $overrides));
    }
}
