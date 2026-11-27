<?php

namespace Tests\Feature;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Stage4cPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const STAGE4C_PERMISSIONS = [
        'financials.refunds.view', 'financials.refunds.initiate', 'financials.refunds.approve',
        'financials.credit_notes.view', 'financials.credit_notes.create', 'financials.credit_notes.approve',
        'financials.credit_reversals.view', 'financials.credit_reversals.initiate', 'financials.credit_reversals.approve',
    ];

    public function test_stage4c_permissions_are_seeded_and_granted_only_to_super_admin_by_default(): void
    {
        app(RoleAndPermissionSeeder::class)->run();

        foreach (self::STAGE4C_PERMISSIONS as $slug) {
            $this->assertTrue(Permission::where('slug', $slug)->exists(), "Missing permission {$slug}");
        }

        $superAdmin = Role::where('slug', 'super-admin')->firstOrFail();
        foreach (self::STAGE4C_PERMISSIONS as $slug) {
            $this->assertTrue($superAdmin->permissions()->where('slug', $slug)->exists(), "super-admin missing {$slug}");
        }

        $admin = Role::where('slug', 'admin')->firstOrFail();
        $this->assertFalse($admin->permissions()->whereIn('slug', self::STAGE4C_PERMISSIONS)->exists());
    }

    public function test_super_admin_can_reach_every_stage4c_admin_endpoint(): void
    {
        app(RoleAndPermissionSeeder::class)->run();
        $superAdmin = User::factory()->create(['is_active' => true]);
        $superAdmin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail());
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/financials/refunds')->assertOk();
        $this->getJson('/api/v1/admin/financials/refunds/eligible-payments')->assertOk();
        $this->getJson('/api/v1/admin/financials/credit-notes')->assertOk();
        $this->getJson('/api/v1/admin/financials/credit-notes/eligible-invoices')->assertOk();
        $this->getJson('/api/v1/admin/financials/credit-reversals')->assertOk();
        $this->getJson('/api/v1/admin/financials/credit-reversals/eligible')->assertOk();
    }

    public function test_explicit_view_permissions_allow_reading_but_never_mutation(): void
    {
        $viewer = $this->staffWith([
            'financials.refunds.view', 'financials.credit_notes.view', 'financials.credit_reversals.view',
        ]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/admin/financials/refunds')->assertOk();
        $this->getJson('/api/v1/admin/financials/credit-notes')->assertOk();
        $this->getJson('/api/v1/admin/financials/credit-reversals')->assertOk();

        $this->postJson('/api/v1/admin/financials/refunds', [
            'payment_transaction_id' => 1, 'amount' => '10.00', 'reason' => 'Viewer cannot initiate',
            'refund_reference' => 'perm-refund-0001',
        ])->assertForbidden();
        $this->postJson('/api/v1/admin/financials/credit-notes', [
            'originating_document_id' => 1, 'amount' => '10.00',
        ])->assertForbidden();
        $this->postJson('/api/v1/admin/financials/credit-reversals', [
            'ledger_entry_id' => 1, 'amount' => '10.00', 'reason' => 'Viewer cannot initiate',
            'operation_reference' => 'perm-reversal-0001',
        ])->assertForbidden();
    }

    public function test_initiators_cannot_use_approval_endpoints(): void
    {
        $initiator = $this->staffWith([
            'financials.refunds.initiate', 'financials.credit_notes.create', 'financials.credit_reversals.initiate',
        ]);
        $customer = Customer::create([
            'name' => 'Stage 4C Initiator Customer', 'phone' => '0200000911', 'email' => 'stage4c-initiator@example.test',
            'password' => 'old-customer-password', 'is_active' => true,
        ]);
        $note = CommercialDocument::create([
            'document_number' => 'CN-PERM-1', 'document_type' => 'credit_note', 'status' => 'draft',
            'customer_id' => $customer->id, 'currency' => 'GHS', 'subtotal' => '10.00',
            'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => '10.00',
        ]);
        Sanctum::actingAs($initiator);

        $this->postJson('/api/v1/admin/financials/refunds/1/approve')->assertForbidden();
        $this->postJson('/api/v1/admin/financials/refunds/1/confirm', ['evidence' => 'x', 'provider_reference' => 'x'])->assertForbidden();
        $this->postJson('/api/v1/admin/financials/credit-notes/'.$note->id.'/issue')->assertForbidden();
        $this->postJson('/api/v1/admin/financials/credit-notes/'.$note->id.'/void', ['reason' => 'nope'])->assertForbidden();
        $this->postJson('/api/v1/admin/financials/credit-reversals/1/approve')->assertForbidden();
        $this->postJson('/api/v1/admin/financials/credit-reversals/1/reject', ['reason' => 'nope'])->assertForbidden();
    }

    public function test_ordinary_admin_and_customers_are_denied_every_stage4c_admin_route(): void
    {
        app(RoleAndPermissionSeeder::class)->run();

        $ordinaryAdmin = User::factory()->create(['is_active' => true]);
        $ordinaryAdmin->roles()->attach(Role::where('slug', 'admin')->firstOrFail());
        Sanctum::actingAs($ordinaryAdmin);

        foreach ($this->stage4cReadPaths() as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->postJson('/api/v1/admin/financials/credit-notes', ['originating_document_id' => 1, 'amount' => '1.00'])->assertForbidden();

        $customer = Customer::create([
            'name' => 'Stage 4C Permission Customer', 'phone' => '0200000910', 'email' => 'stage4c-perm@example.test',
            'password' => 'old-customer-password', 'is_active' => true,
        ]);
        Sanctum::actingAs($customer);

        foreach ($this->stage4cReadPaths() as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->postJson('/api/v1/admin/financials/refunds', [
            'payment_transaction_id' => 1, 'amount' => '1.00', 'reason' => 'Customer cannot refund',
            'refund_reference' => 'perm-refund-0002',
        ])->assertForbidden();
    }

    private function stage4cReadPaths(): array
    {
        return [
            '/api/v1/admin/financials/refunds',
            '/api/v1/admin/financials/refunds/eligible-payments',
            '/api/v1/admin/financials/credit-notes',
            '/api/v1/admin/financials/credit-notes/eligible-invoices',
            '/api/v1/admin/financials/credit-reversals',
            '/api/v1/admin/financials/credit-reversals/eligible',
        ];
    }

    private function staffWith(array $slugs): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'Role '.uniqid(), 'slug' => 'role-'.uniqid()]);
        foreach ($slugs as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'description' => $slug]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);

        return $user;
    }
}
