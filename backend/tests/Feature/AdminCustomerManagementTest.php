<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Customer Support → Customers.
 *
 * Pins the administrative customer contract: server-side search/filter/sort and
 * pagination, CRUD with normalized identity rules, archive instead of delete,
 * granular RBAC on every endpoint, and financial figures behind their own
 * permission.
 */
class AdminCustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/customers';

    protected function setUp(): void
    {
        parent::setUp();
        app(RoleAndPermissionSeeder::class)->run();
    }

    // ------------------------------------------------------------- listing

    public function test_listing_is_paginated_and_reports_counts_without_secrets(): void
    {
        $this->customer(['name' => 'First Customer', 'phone' => '0244000001', 'email' => 'first@example.test']);
        $this->customer(['name' => 'Second Customer', 'phone' => '0244000002', 'email' => 'second@example.test']);
        $this->customer(['name' => 'Third Customer', 'phone' => '0244000003', 'email' => 'third@example.test']);

        $response = $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson(self::BASE.'?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('counts.total', 3)
            ->assertJsonPath('counts.active', 3)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [[
                'id', 'reference', 'name', 'email', 'phone', 'status', 'customer_type',
                'email_verified', 'phone_verified', 'joined_at', 'last_activity_at', 'archived_at',
            ]]]);

        $first = $response->json('data.0');
        $this->assertArrayNotHasKey('password', $first);
        $this->assertArrayNotHasKey('remember_token', $first);
        $this->assertArrayNotHasKey('payments_received', $first);
        $this->assertStringStartsWith('CUS-', $first['reference']);
    }

    public function test_search_matches_name_email_and_normalized_phone(): void
    {
        $this->customer(['name' => 'Akosua Mensah', 'phone' => '0244000111', 'email' => 'akosua@example.test']);
        $this->customer(['name' => 'Kofi Boateng', 'phone' => '0244000222', 'email' => 'kofi@example.test']);

        $actor = $this->superAdmin();

        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?search=akosua')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Akosua Mensah');

        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?search=kofi@example')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Kofi Boateng');

        // The same number typed in local form still finds the stored value.
        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?search='.urlencode('0244 000 111'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Akosua Mensah');
    }

    public function test_status_and_type_filters_run_on_the_server(): void
    {
        $active = $this->customer(['name' => 'Active Person', 'phone' => '0244001001', 'email' => 'active@example.test']);
        $inactive = $this->customer(['name' => 'Inactive Person', 'phone' => '0244001002', 'email' => 'inactive@example.test']);
        $inactive->forceFill(['is_active' => false])->save();
        $archived = $this->customer(['name' => 'Archived Person', 'phone' => '0244001003', 'email' => 'archived@example.test']);
        $archived->forceFill(['is_active' => false, 'archived_at' => now()])->save();

        $organisation = Organisation::create([
            'reference' => 'ORG-'.uniqid(), 'owner_customer_id' => $active->id,
            'name' => 'Mensah Catering', 'status' => Organisation::STATUS_ACTIVE,
        ]);
        OrganisationMembership::create([
            'organisation_id' => $organisation->id, 'customer_id' => $active->id,
            'role' => OrganisationMembership::ROLE_OWNER, 'status' => OrganisationMembership::STATUS_ACTIVE,
        ]);

        $actor = $this->superAdmin();

        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?status=archived')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'archived');
        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?status=inactive')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'inactive');
        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?status=active')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?type=organisation')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_type', 'organisation');
        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?type=individual')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_listing_sorts_by_name_and_joined_date(): void
    {
        $zoe = $this->customer(['name' => 'Zoe Last', 'phone' => '0244002001', 'email' => 'zoe@example.test']);
        $adam = $this->customer(['name' => 'Adam First', 'phone' => '0244002002', 'email' => 'adam@example.test']);
        $zoe->forceFill(['created_at' => now()->subDays(2)])->save();
        $adam->forceFill(['created_at' => now()->subDay()])->save();

        $actor = $this->superAdmin();

        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?sort=name&direction=asc')
            ->assertOk()->assertJsonPath('data.0.name', 'Adam First');
        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?sort=name&direction=desc')
            ->assertOk()->assertJsonPath('data.0.name', 'Zoe Last');
        $this->actingAs($actor, 'sanctum')->getJson(self::BASE.'?sort=joined&direction=asc')
            ->assertOk()->assertJsonPath('data.0.name', 'Zoe Last');
    }

    public function test_invalid_listing_filters_are_rejected(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson(self::BASE.'?status=deleted&type=alien&sort=password')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'type', 'sort']);
    }

    // -------------------------------------------------------------- create

    public function test_admin_creates_a_customer_with_normalized_contact_details(): void
    {
        $actor = $this->superAdmin();

        $response = $this->actingAs($actor, 'sanctum')->postJson(self::BASE, [
            'name' => '  Ama Serwaa  ',
            'email' => '  AMA.SERWAA@Example.TEST ',
            'phone' => '024 400 0111',
        ])->assertCreated()->assertJsonPath('data.name', 'Ama Serwaa')
            ->assertJsonPath('data.email', 'ama.serwaa@example.test')
            ->assertJsonPath('data.phone', '+233244000111')
            ->assertJsonPath('data.status', 'active');

        $customer = Customer::query()->findOrFail($response->json('data.id'));
        $this->assertTrue((bool) $customer->is_active);
        $this->assertNull($customer->archived_at);
        $this->assertNull($customer->email_verified_at);

        $this->assertDatabaseHas('admin_audit_logs', [
            'user_id' => $actor->id, 'action' => 'customer.created', 'entity_id' => (string) $customer->id,
        ]);
        $this->assertDatabaseHas('customer_audit_logs', [
            'customer_id' => $customer->id, 'action' => 'created',
        ]);
    }

    public function test_customer_creation_validates_required_and_contact_fields(): void
    {
        $actor = $this->superAdmin();

        $this->actingAs($actor, 'sanctum')->postJson(self::BASE, [])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'phone']);

        $this->actingAs($actor, 'sanctum')->postJson(self::BASE, [
            'name' => 'A', 'email' => 'not-an-email', 'phone' => 'abc',
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'phone']);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_customer_creation_rejects_duplicate_identity_details(): void
    {
        $existing = $this->customer(['name' => 'Existing Customer', 'phone' => '+233244000999', 'email' => 'existing@example.test']);
        $actor = $this->superAdmin();

        $this->actingAs($actor, 'sanctum')->postJson(self::BASE, [
            'name' => 'Copy Cat', 'email' => 'EXISTING@example.test', 'phone' => '0244000301',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->actingAs($actor, 'sanctum')->postJson(self::BASE, [
            'name' => 'Copy Cat', 'email' => 'copy@example.test', 'phone' => '0244000999',
        ])->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame('Existing Customer', $existing->fresh()->name);
    }

    // ---------------------------------------------------------------- read

    public function test_customer_detail_returns_profile_and_activity_without_financials(): void
    {
        $customer = $this->customer(['name' => 'Detail Customer', 'phone' => '0244004001', 'email' => 'detail@example.test']);
        $this->payment($customer, 120.00, 'DETAIL-PAY-1');

        $response = $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson(self::BASE.'/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.customer.status', 'active')
            ->assertJsonStructure(['data' => [
                'customer' => ['id', 'reference', 'name', 'email', 'phone', 'status', 'customer_type', 'memberships'],
                'activity',
            ]]);

        $payload = $response->json('data');
        $this->assertArrayNotHasKey('password', $payload['customer']);
        $this->assertArrayNotHasKey('payments_received', $payload['customer']);
        $this->assertArrayNotHasKey('credit_balance', $payload['customer']);
    }

    public function test_unknown_customer_returns_not_found(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')->getJson(self::BASE.'/99999')->assertNotFound();
    }

    // -------------------------------------------------------------- update

    public function test_update_persists_changes_and_clears_verification_of_changed_contact(): void
    {
        $customer = $this->customer([
            'name' => 'Before Name', 'phone' => '+233244000501', 'email' => 'before@example.test',
            'email_verified_at' => now(), 'phone_verified_at' => now(),
        ]);
        $actor = $this->superAdmin();

        $this->actingAs($actor, 'sanctum')->putJson(self::BASE.'/'.$customer->id, [
            'name' => 'After Name',
            'email' => 'after@example.test',
            'phone' => '+233244000501',
        ])->assertOk()->assertJsonPath('data.name', 'After Name');

        $customer->refresh();
        $this->assertSame('After Name', $customer->name);
        $this->assertSame('after@example.test', $customer->email);
        $this->assertNull($customer->email_verified_at, 'A changed email must lose its verification.');
        $this->assertNotNull($customer->phone_verified_at, 'An unchanged phone keeps its verification.');

        $audit = AdminAuditLog::query()->where('action', 'customer.updated')->where('entity_id', (string) $customer->id)->firstOrFail();
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertArrayHasKey('name', $audit->new_values);
    }

    public function test_update_rejects_duplicate_contact_details_and_keeps_the_original(): void
    {
        $first = $this->customer(['name' => 'First', 'phone' => '+233244000601', 'email' => 'first@example.test']);
        $second = $this->customer(['name' => 'Second', 'phone' => '+233244000602', 'email' => 'second@example.test']);

        $this->actingAs($this->superAdmin(), 'sanctum')->putJson(self::BASE.'/'.$second->id, [
            'email' => 'FIRST@example.test',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertSame('second@example.test', $second->fresh()->email);
        $this->assertSame('first@example.test', $first->fresh()->email);
    }

    public function test_mass_assignment_cannot_change_protected_fields(): void
    {
        $actor = $this->superAdmin();

        $response = $this->actingAs($actor, 'sanctum')->postJson(self::BASE, [
            'name' => 'Mass Assign', 'phone' => '0244000701', 'email' => 'mass@example.test',
            'id' => 999, 'is_active' => false, 'archived_at' => now()->toDateTimeString(),
            'email_verified_at' => now()->toDateTimeString(), 'password' => 'attacker-chosen',
        ])->assertCreated();

        $customer = Customer::query()->findOrFail($response->json('data.id'));
        $this->assertNotSame(999, $customer->id);
        $this->assertTrue((bool) $customer->is_active);
        $this->assertNull($customer->archived_at);
        $this->assertNull($customer->email_verified_at);
        $this->assertFalse(Hash::check('attacker-chosen', (string) $customer->password));
    }

    // ------------------------------------------------------- archive/restore

    public function test_archive_preserves_financial_records_and_there_is_no_hard_delete(): void
    {
        $customer = $this->customer(['name' => 'Archive Me', 'phone' => '0244000801', 'email' => 'archive@example.test']);
        $payment = $this->payment($customer, 300.00, 'ARCHIVE-PAY-1');
        $invoice = $this->invoice($customer, 300.00, 'INV-ARCHIVE-1');

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson(self::BASE.'/'.$customer->id.'/archive')
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $customer->refresh();
        $this->assertNotNull($customer->archived_at);
        $this->assertFalse((bool) $customer->is_active);

        // Nothing financial is removed, and no cascade is introduced.
        $this->assertDatabaseHas('payment_transactions', ['id' => $payment->id, 'customer_id' => $customer->id]);
        $this->assertDatabaseHas('commercial_documents', ['id' => $invoice->id, 'customer_id' => $customer->id]);

        // There is no delete endpoint at all: removal is an archive.
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->deleteJson(self::BASE.'/'.$customer->id)
            ->assertStatus(405);

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_archiving_twice_is_rejected_and_audited_once(): void
    {
        $customer = $this->customer(['name' => 'Twice', 'phone' => '0244000901', 'email' => 'twice@example.test']);
        $actor = $this->superAdmin();

        $this->actingAs($actor, 'sanctum')->postJson(self::BASE.'/'.$customer->id.'/archive')->assertOk();
        $this->actingAs($actor, 'sanctum')->postJson(self::BASE.'/'.$customer->id.'/archive')->assertStatus(409);

        $this->assertSame(1, AdminAuditLog::query()->where('action', 'customer.archived')->count());
    }

    public function test_restore_returns_an_archived_customer_to_active(): void
    {
        $customer = $this->customer(['name' => 'Restore Me', 'phone' => '0244001001', 'email' => 'restore@example.test']);
        $customer->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        $actor = $this->superAdmin();

        $this->actingAs($actor, 'sanctum')
            ->postJson(self::BASE.'/'.$customer->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $customer->refresh();
        $this->assertNull($customer->archived_at);
        $this->assertTrue((bool) $customer->is_active);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'customer.restored', 'entity_id' => (string) $customer->id]);

        $this->actingAs($actor, 'sanctum')->postJson(self::BASE.'/'.$customer->id.'/restore')->assertStatus(409);
    }

    public function test_archived_customer_cannot_sign_in_or_be_treated_as_active(): void
    {
        $customer = $this->customer(['name' => 'Locked Out', 'phone' => '0244001101', 'email' => 'locked@example.test']);

        $this->postJson('/api/v1/customers/login', [
            'email' => 'locked@example.test', 'password' => 'customer-password',
        ])->assertOk();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson(self::BASE.'/'.$customer->id.'/archive')->assertOk();

        $this->postJson('/api/v1/customers/login', [
            'email' => 'locked@example.test', 'password' => 'customer-password',
        ])->assertStatus(403);
    }

    // ---------------------------------------------------------- permissions

    public function test_every_operation_requires_its_own_customers_permission(): void
    {
        $customer = $this->customer(['name' => 'Guarded', 'phone' => '0244001201', 'email' => 'guarded@example.test']);
        $customer->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        $activeCustomer = $this->customer(['name' => 'Lifecycle', 'phone' => '0244001204', 'email' => 'lifecycle@example.test']);

        $viewer = $this->staffWith(['customers.view']);
        $this->actingAs($viewer, 'sanctum')->getJson(self::BASE)->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson(self::BASE.'/'.$customer->id)->assertOk();
        $this->actingAs($viewer, 'sanctum')->postJson(self::BASE, ['name' => 'Nope', 'phone' => '0244001202'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->putJson(self::BASE.'/'.$customer->id, ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson(self::BASE.'/'.$customer->id.'/archive')->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson(self::BASE.'/'.$customer->id.'/restore')->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->getJson(self::BASE.'/'.$customer->id.'/financials')->assertForbidden();

        $creator = $this->staffWith(['customers.create']);
        $this->actingAs($creator, 'sanctum')->postJson(self::BASE, ['name' => 'Created Only', 'phone' => '0244001203'])->assertCreated();
        $this->actingAs($creator, 'sanctum')->getJson(self::BASE)->assertForbidden();

        $archiver = $this->staffWith(['customers.archive']);
        $this->actingAs($archiver, 'sanctum')->postJson(self::BASE.'/'.$customer->id.'/restore')->assertForbidden();
        $this->actingAs($archiver, 'sanctum')->postJson(self::BASE.'/'.$activeCustomer->id.'/archive')->assertOk();

        $restorer = $this->staffWith(['customers.restore']);
        $this->actingAs($restorer, 'sanctum')->postJson(self::BASE.'/'.$activeCustomer->id.'/archive')->assertForbidden();
        $this->actingAs($restorer, 'sanctum')->postJson(self::BASE.'/'.$activeCustomer->id.'/restore')->assertOk();
    }

    public function test_unauthenticated_and_customer_tokens_cannot_reach_customer_administration(): void
    {
        $customer = $this->customer(['name' => 'Token Customer', 'phone' => '0244001301', 'email' => 'token@example.test']);

        $this->getJson(self::BASE)->assertUnauthorized();

        // A signed-in customer account is not a staff account.
        $this->actingAs($customer, 'sanctum')->getJson(self::BASE)->assertForbidden();
        $this->actingAs($customer, 'sanctum')->getJson(self::BASE.'/'.$customer->id)->assertForbidden();
    }

    public function test_customer_view_permission_does_not_reveal_financial_information(): void
    {
        $customer = $this->customer(['name' => 'Money Customer', 'phone' => '0244001401', 'email' => 'money@example.test']);
        $this->payment($customer, 480.00, 'MONEY-PAY-1');

        $viewer = $this->staffWith(['customers.view']);

        $detail = $this->actingAs($viewer, 'sanctum')->getJson(self::BASE.'/'.$customer->id)->assertOk();
        $this->assertArrayNotHasKey('financials', $detail->json('data'));
        $this->assertArrayNotHasKey('payments_received', $detail->json('data.customer'));

        $this->actingAs($viewer, 'sanctum')->getJson(self::BASE.'/'.$customer->id.'/financials')->assertForbidden();

        $financeViewer = $this->staffWith(['customers.view', 'customers.view_financials']);
        $this->actingAs($financeViewer, 'sanctum')
            ->getJson(self::BASE.'/'.$customer->id.'/financials')
            ->assertOk()
            ->assertJsonPath('data.payments_received', '480.00')
            ->assertJsonPath('data.deposits_total', '0.00')
            ->assertJsonPath('data.credit_balance', '0.00')
            ->assertJsonPath('data.outstanding_invoices', '0.00')
            ->assertJsonPath('data.refunds_total', '0.00');
    }

    public function test_financial_overview_reports_outstanding_invoices_from_the_allocation_service(): void
    {
        $customer = $this->customer(['name' => 'Invoice Customer', 'phone' => '0244001501', 'email' => 'invoice@example.test']);
        $this->payment($customer, 250.00, 'INV-PAY-1');
        $this->invoice($customer, 100.00, 'INV-1001');

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson(self::BASE.'/'.$customer->id.'/financials')
            ->assertOk()
            ->assertJsonPath('data.currency', 'GHS')
            ->assertJsonPath('data.payments_received', '250.00')
            ->assertJsonPath('data.payments_count', 1)
            ->assertJsonPath('data.outstanding_invoices', '100.00')
            ->assertJsonPath('data.outstanding_invoice_count', 1);
    }

    public function test_customer_management_permissions_are_not_granted_to_every_administrator(): void
    {
        $slugs = ['customers.view', 'customers.create', 'customers.update', 'customers.archive', 'customers.restore', 'customers.view_financials'];

        foreach ($slugs as $slug) {
            $this->assertDatabaseHas('permissions', ['slug' => $slug]);
        }

        $superAdmin = Role::query()->where('slug', 'super-admin')->firstOrFail();
        $admin = Role::query()->where('slug', 'admin')->firstOrFail();

        foreach ($slugs as $slug) {
            $this->assertTrue($superAdmin->hasPermission($slug), "Super Admin must hold {$slug}.");
            $this->assertFalse($admin->hasPermission($slug), "The default Admin role must not be granted {$slug}.");
        }

        // Re-seeding must not remove the migration's grants from super-admin,
        // and must not hand them to every administrator.
        app(RoleAndPermissionSeeder::class)->run();

        foreach ($slugs as $slug) {
            $this->assertTrue($superAdmin->fresh()->hasPermission($slug));
            $this->assertFalse($admin->fresh()->hasPermission($slug));
        }
    }

    // -------------------------------------------------------------- helpers

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes = []): Customer
    {
        // Verification timestamps are intentionally not mass assignable.
        $verification = array_intersect_key($attributes, array_flip(['email_verified_at', 'phone_verified_at']));

        $customer = Customer::create(array_merge([
            'name' => 'Test Customer',
            'phone' => '0244'.random_int(100000, 999999),
            'email' => 'customer-'.uniqid().'@example.test',
            'password' => 'customer-password',
            'is_active' => true,
        ], array_diff_key($attributes, $verification)));

        if ($verification !== []) {
            $customer->forceFill($verification)->save();
        }

        return $customer->refresh();
    }

    private function payment(Customer $customer, float $amount, string $reference): PaymentTransaction
    {
        return PaymentTransaction::create([
            'order_id' => null, 'customer_id' => $customer->id, 'organisation_id' => null, 'payment_purpose' => 'invoice',
            'transaction_reference' => $reference, 'payment_method' => 'cash', 'amount' => $amount, 'currency' => 'GHS',
            'status' => 'succeeded', 'verified_at' => now(), 'received_at' => now(), 'reporting_date' => now()->toDateString(),
        ]);
    }

    private function invoice(Customer $customer, float $total, string $number): CommercialDocument
    {
        return CommercialDocument::create([
            'document_number' => $number, 'document_type' => CommercialDocument::TYPE_INVOICE,
            'status' => CommercialDocument::STATUS_ISSUED, 'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(), 'currency' => 'GHS',
            'subtotal' => $total, 'total' => $total, 'issued_at' => now(),
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('slug', 'super-admin')->firstOrFail());

        return $user;
    }

    /** @param list<string> $slugs */
    private function staffWith(array $slugs): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'Role '.uniqid(), 'slug' => 'role-'.uniqid()]);
        foreach ($slugs as $slug) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->where('slug', $slug)->firstOrFail()->id]);
        }
        $user->roles()->attach($role);

        return $user;
    }
}
