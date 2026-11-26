<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\OrganisationMembershipPermission;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_four_approved_roles_exist(): void
    {
        $expectedRoles = [
            'super-admin',
            'waiter-waitress',
            'kitchen-staff',
            'delivery-staff',
        ];

        foreach ($expectedRoles as $slug) {
            $this->assertDatabaseHas('roles', ['slug' => $slug]);
        }
    }

    public function test_administrator_has_all_permissions(): void
    {
        $adminRole = Role::where('slug', 'super-admin')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($adminRole);

        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertTrue($user->hasPermission('menu.manage'));
        $this->assertTrue($user->hasPermission('menu.price.edit'));
        $this->assertTrue($user->hasPermission('settings.manage'));
        $this->assertTrue($user->hasPermission('orders.view'));
    }

    public function test_waiter_cannot_edit_menu_prices(): void
    {
        $waiterRole = Role::where('slug', 'waiter-waitress')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($waiterRole);

        $this->assertTrue($user->hasRole('waiter-waitress'));
        $this->assertTrue($user->hasPermission('orders.view'));
        $this->assertTrue($user->hasPermission('orders.create'));
        $this->assertTrue($user->hasPermission('menu.view'));

        // Waiter must NOT have menu price editing or settings management
        $this->assertFalse($user->hasPermission('menu.price.edit'));
        $this->assertFalse($user->hasPermission('settings.manage'));
    }

    public function test_kitchen_staff_cannot_access_administrative_settings(): void
    {
        $kitchenRole = Role::where('slug', 'kitchen-staff')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($kitchenRole);

        $this->assertTrue($user->hasRole('kitchen-staff'));
        $this->assertTrue($user->hasPermission('orders.view'));
        $this->assertTrue($user->hasPermission('orders.prepare'));
        $this->assertTrue($user->hasPermission('orders.ready'));
        $this->assertTrue($user->hasPermission('menu.view'));

        // Kitchen staff must NOT have broad status update, settings, or price editing
        $this->assertFalse($user->hasPermission('orders.status.update'));
        $this->assertFalse($user->hasPermission('settings.manage'));
        $this->assertFalse($user->hasPermission('menu.price.edit'));
    }

    public function test_delivery_staff_has_restricted_permissions(): void
    {
        $deliveryRole = Role::where('slug', 'delivery-staff')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($deliveryRole);

        $this->assertTrue($user->hasRole('delivery-staff'));
        $this->assertTrue($user->hasPermission('orders.view'));
        $this->assertTrue($user->hasPermission('delivery_zones.view'));

        // Delivery staff must NOT have broad status update, settings, or menu price editing
        $this->assertFalse($user->hasPermission('orders.status.update'));
        $this->assertFalse($user->hasPermission('settings.manage'));
        $this->assertFalse($user->hasPermission('menu.price.edit'));
    }

    public function test_user_extension_fields_are_functional(): void
    {
        $user = User::factory()->create([
            'phone' => '+1234567890',
            'is_active' => true,
        ]);

        $this->assertEquals('+1234567890', $user->phone);
        $this->assertTrue($user->is_active);

        $user->update(['is_active' => false]);
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_super_admin_effective_permissions_match_login_and_me_without_role_pivot_grants(): void
    {
        $role = Role::where('slug', 'super-admin')->firstOrFail();
        $role->permissions()->detach();
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $expected = Permission::query()->orderBy('slug')->pluck('slug')->all();
        $this->assertTrue($user->hasPermission('financials.allocations.view'));
        $this->assertTrue($user->hasAnyPermission(['commercial_documents.view']));
        $this->assertSame($expected, $user->effectivePermissionSlugs());

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.roles.0', 'super-admin')
            ->json('data.user.permissions');

        Sanctum::actingAs($user);
        $me = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.permissions');

        $this->assertSame($expected, $login);
        $this->assertSame($login, $me);
        $this->assertContains('financials.allocations.view', $me);
        $this->assertContains('financials.allocations.manage', $me);
        $this->assertContains('financials.allocations.reverse', $me);
        $this->assertContains('commercial_documents.view', $me);
    }

    public function test_super_admin_backend_allows_allocation_and_commercial_read_routes(): void
    {
        $role = Role::where('slug', 'super-admin')->firstOrFail();
        $role->permissions()->detach();
        $user = User::factory()->create();
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/financials/payment-allocations/payments')->assertOk();
        $this->getJson('/api/v1/admin/commercial-documents')->assertOk();
    }

    public function test_ordinary_staff_permissions_remain_explicit_and_operation_specific(): void
    {
        $role = Role::create(['name' => 'Finance Viewer', 'slug' => 'finance-viewer']);
        $viewer = User::factory()->create();
        $viewer->roles()->attach($role);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/admin/financials/payment-allocations/payments')->assertForbidden();
        $role->permissions()->attach(Permission::where('slug', 'financials.allocations.view')->value('id'));
        $viewer->unsetRelation('roles');
        $this->getJson('/api/v1/admin/financials/payment-allocations/payments')->assertOk();
        $this->postJson('/api/v1/admin/financials/payment-allocations/preview', [
            'payment_transaction_id' => 1,
            'allocations' => [['commercial_document_id' => 1, 'amount' => '1.00']],
        ])->assertForbidden();

        $viewer->unsetRelation('roles');
        $this->assertTrue($viewer->hasPermission('financials.allocations.view'));
        $this->assertFalse($viewer->hasPermission('financials.allocations.manage'));
        $this->assertFalse($viewer->hasPermission('financials.allocations.reverse'));

        $role->permissions()->attach(Permission::where('slug', 'financials.allocations.manage')->value('id'));
        $viewer->unsetRelation('roles');
        $this->assertTrue($viewer->hasPermission('financials.allocations.manage'));
        $this->assertFalse($viewer->hasPermission('financials.allocations.reverse'));
    }

    public function test_client_submitted_roles_and_permissions_do_not_elevate_staff_login(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', 'admin')->firstOrFail());

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'roles' => ['super-admin'],
            'permissions' => ['financials.allocations.manage', 'financials.allocations.reverse'],
        ])->assertOk()
            ->assertJsonPath('data.user.roles.0', 'admin')
            ->json();

        $this->assertNotContains('financials.allocations.manage', $login['data']['user']['permissions']);
        $this->withToken($login['data']['token'])
            ->getJson('/api/v1/admin/financials/payment-allocations/payments')
            ->assertForbidden();
    }

    public function test_organisation_membership_permissions_are_not_staff_permissions(): void
    {
        $customer = \App\Models\Customer::create([
            'name' => 'Org Member',
            'phone' => '0200000999',
            'email' => 'org-member@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $organisation = Organisation::create([
            'owner_customer_id' => $customer->id,
            'name' => 'Test Organisation',
            'reference' => 'ORG-RBAC-1',
        ]);
        $membership = OrganisationMembership::create([
            'organisation_id' => $organisation->id,
            'customer_id' => $customer->id,
            'role' => OrganisationMembership::ROLE_OWNER,
            'status' => OrganisationMembership::STATUS_ACTIVE,
        ]);
        OrganisationMembershipPermission::create([
            'organisation_membership_id' => $membership->id,
            'permission' => 'organisation.private.permission',
            'granted_at' => now(),
        ]);

        $staffRole = Role::where('slug', 'waiter-waitress')->firstOrFail();
        $staff = User::factory()->create();
        $staff->roles()->attach($staffRole);

        $this->assertNotContains('organisation.private.permission', $staff->effectivePermissionSlugs());
    }

    public function test_seeder_keeps_stage_permissions_and_later_staff_permissions_without_expanding_admin_financial_access(): void
    {
        $future = Permission::create([
            'name' => 'Approved Later Permission',
            'slug' => 'approved_later.permission',
            'description' => 'Permission introduced by a later approved migration',
        ]);
        $superAdmin = Role::where('slug', 'super-admin')->firstOrFail();
        $superAdmin->permissions()->detach();
        $superAdmin->permissions()->attach($future);

        $this->seed(RoleAndPermissionSeeder::class);

        $superAdmin->refresh();
        $this->assertTrue($superAdmin->hasPermission('financials.allocations.view'));
        $this->assertTrue($superAdmin->hasPermission('financials.allocations.manage'));
        $this->assertTrue($superAdmin->hasPermission('financials.allocations.reverse'));
        $this->assertTrue($superAdmin->hasPermission('financials.invoice_balances.view'));
        $this->assertTrue($superAdmin->hasPermission('commercial_documents.view'));
        $this->assertTrue($superAdmin->hasPermission('approved_later.permission'));

        $admin = Role::where('slug', 'admin')->firstOrFail();
        $this->assertTrue($admin->hasPermission('commercial_documents.view'));
        $this->assertFalse($admin->hasPermission('financials.allocations.manage'));

        $firstAssignments = Role::query()->with('permissions:id')->get()
            ->mapWithKeys(fn (Role $role) => [$role->slug => $role->permissions->pluck('id')->sort()->values()->all()])
            ->all();
        $this->seed(RoleAndPermissionSeeder::class);
        $secondAssignments = Role::query()->with('permissions:id')->get()
            ->mapWithKeys(fn (Role $role) => [$role->slug => $role->permissions->pluck('id')->sort()->values()->all()])
            ->all();

        $this->assertSame($firstAssignments, $secondAssignments);
    }
}
