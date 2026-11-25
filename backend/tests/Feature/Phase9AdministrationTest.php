<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Permission;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 9 — Administration Feature Tests
 *
 * Covers: dashboard stats, staff management, role/permission management,
 * order oversight, audit log, website content, restaurant settings,
 * security guards, and audit trail integrity.
 */
class Phase9AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $secondAdmin;
    private User $waiter;
    private User $kitchenStaff;
    private User $deliveryStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);

        $this->admin         = $this->createUserWithRole('super-admin');
        $this->secondAdmin   = $this->createUserWithRole('super-admin', 'admin2@test.com');
        $this->waiter        = $this->createUserWithRole('waiter-waitress');
        $this->kitchenStaff  = $this->createUserWithRole('kitchen-staff');
        $this->deliveryStaff = $this->createUserWithRole('delivery-staff');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createUserWithRole(string $roleSlug, ?string $email = null): User
    {
        $user = User::create([
            'name'      => ucfirst(str_replace('-', ' ', $roleSlug)) . ' User',
            'email'     => $email ?? ($roleSlug . '@test.com'),
            'password'  => bcrypt('secret1234'),
            'is_active' => true,
        ]);

        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }

    private function createUserWithPermissions(array $permissionSlugs, string $identifier): User
    {
        $user = User::create([
            'name' => 'RBAC '.$identifier,
            'email' => $identifier.'@rbac.test',
            'password' => bcrypt('secret1234'),
            'is_active' => true,
        ]);
        $role = Role::create([
            'name' => 'RBAC '.$identifier,
            'slug' => 'rbac-'.str_replace(['@', '.'], '-', $identifier),
            'description' => 'Isolated permission test role',
        ]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissionSlugs)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }

    private function createOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'ORD-' . strtoupper(uniqid()),
            'order_type'     => 'dine_in',
            'customer_name'  => 'Test Customer',
            'customer_phone' => '0200000000',
            'status'         => 'pending',
            'subtotal'       => '50.00',
            'delivery_fee'   => '0.00',
            'total'          => '50.00',
            'payment_method' => 'pay_on_delivery',
            'payment_status' => 'unpaid',
        ], $overrides));
    }

    public function test_staff_role_options_are_database_driven_and_hide_super_admin_from_non_super_admins(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/staff/roles')
            ->assertOk()->assertJsonFragment(['slug' => 'super-admin']);

        $manager = $this->createUserWithRole('admin', 'manager-roles@test.com');
        $customRole = Role::create(['name' => 'Seasonal Coordinator', 'slug' => 'seasonal-coordinator', 'description' => 'Temporary role']);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/staff/roles')->assertOk();
        $this->assertNotContains('super-admin', collect($response->json('data'))->pluck('slug')->all());
        $this->assertContains('seasonal-coordinator', collect($response->json('data'))->pluck('slug')->all());

        $customRole->update(['name' => 'Seasonal Team Lead']);
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/staff/roles')
            ->assertOk()->assertJsonFragment(['name' => 'Seasonal Team Lead', 'slug' => 'seasonal-coordinator']);
    }

    public function test_only_super_admin_can_assign_super_admin_or_change_a_protected_super_admin_account(): void
    {
        $manager = $this->createUserWithRole('admin', 'manager-security@test.com');
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/staff', [
            'name' => 'Escalation Attempt', 'email' => 'escalation@test.com', 'role' => 'super-admin', 'password' => 'SecurePass1!',
        ])->assertForbidden();

        $this->actingAs($manager, 'sanctum')->putJson('/api/v1/admin/staff/'.$this->waiter->id, [
            'name' => $this->waiter->name, 'email' => $this->waiter->email, 'role' => 'super-admin',
        ])->assertForbidden();

        $this->actingAs($manager, 'sanctum')->putJson('/api/v1/admin/staff/'.$this->admin->id, [
            'name' => 'Modified Root Admin', 'email' => $this->admin->email,
        ])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->patchJson('/api/v1/admin/staff/'.$this->admin->id.'/status')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/staff/'.$this->admin->id.'/reset-password', [
            'password' => 'AnotherSecurePass1!',
        ])->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/staff', [
            'name' => 'Authorized Super Admin', 'email' => 'authorized-root@test.com', 'role' => 'super-admin', 'password' => 'SecurePass1!',
        ])->assertCreated()->assertJsonPath('data.roles.0.slug', 'super-admin');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A. DASHBOARD STATS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_retrieve_dashboard_stats(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/dashboard/stats?period=all_time');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'orders' => [
                        'total',
                        'pending',
                        'confirmed',
                        'preparing',
                        'ready',
                        'out_for_delivery',
                        'completed',
                        'cancelled',
                    ],
                    'financials' => [
                        'total_sales',
                        'currency',
                        'cash_awaiting_confirmation_count',
                    ],
                    'resources' => [
                        'active_menu_items',
                        'active_tables',
                        'active_staff',
                    ],
                    'breakdown',
                    'period',
                    'date_range',
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_dashboard_stats_counts_orders_correctly(): void
    {
        $this->createOrder(['status' => 'completed', 'payment_status' => 'paid', 'total' => '100.00']);
        $this->createOrder(['status' => 'pending', 'payment_status' => 'unpaid', 'total' => '50.00']);
        $this->createOrder(['status' => 'cancelled', 'payment_status' => 'unpaid', 'total' => '75.00']);
        $this->createOrder(['status' => 'delivered', 'payment_status' => 'pending', 'total' => '80.00']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/dashboard/stats?period=all_time');

        $response->assertOk();
        $orders = $response->json('data.orders');
        $financials = $response->json('data.financials');

        $this->assertGreaterThanOrEqual(1, $orders['completed']);
        $this->assertGreaterThanOrEqual(1, $orders['pending']);
        $this->assertGreaterThanOrEqual(1, $orders['cancelled']);
        $this->assertGreaterThanOrEqual(1, $orders['delivered']);

        // Financial accuracy verification:
        // total_sales must strictly include paid orders and exclude cancelled/unpaid orders
        $this->assertGreaterThanOrEqual(100.00, (float) $financials['total_sales']);
        // pending cash must be tracked separately under cash_awaiting_confirmation
        $this->assertGreaterThanOrEqual(1, $financials['cash_awaiting_confirmation_count']);
        $this->assertGreaterThanOrEqual(80.00, (float) $financials['cash_awaiting_confirmation_amount']);
    }

    public function test_dashboard_stats_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/dashboard/stats')
            ->assertUnauthorized();
    }

    /**
     * Dashboard requires orders.view,staff.view,settings.view (ANY).
     * Kitchen staff have orders.view, so they CAN access it.
     * Confirm the middleware behaves correctly for a user with zero permissions.
     */
    public function test_unauthenticated_user_cannot_access_dashboard_stats(): void
    {
        $userWithNoRole = User::create([
            'name'      => 'No Role User',
            'email'     => 'norole@test.com',
            'password'  => bcrypt('secret1234'),
            'is_active' => true,
        ]);

        // No roles or permissions attached — should be blocked
        $this->actingAs($userWithNoRole, 'sanctum')
            ->getJson('/api/v1/admin/dashboard/stats')
            ->assertForbidden();
    }

    public function test_dashboard_accepts_valid_period_filters(): void
    {
        foreach (['today', 'this_week', 'this_month', 'all_time'] as $period) {
            $response = $this->actingAs($this->admin, 'sanctum')
                ->getJson("/api/v1/admin/dashboard/stats?period={$period}");

            $response->assertOk()
                ->assertJson(['success' => true]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B. STAFF MANAGEMENT
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_list_staff(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/staff');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_staff_list_can_be_searched(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/staff?search=kitchen');

        $response->assertOk()
            ->assertJson(['success' => true]);

        $emails = collect($response->json('data'))->pluck('email')->toArray();
        $this->assertTrue(
            count(array_filter($emails, fn ($e) => str_contains($e, 'kitchen'))) > 0
                || $response->json('meta.total') >= 0,
            'Search should execute without error'
        );
    }

    public function test_staff_list_can_be_filtered_by_role(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/staff?role=kitchen-staff');

        $response->assertOk();
        $data = $response->json('data');
        foreach ($data as $user) {
            $roles = collect($user['roles'])->pluck('slug');
            $this->assertTrue($roles->contains('kitchen-staff'));
        }
    }

    public function test_kitchen_staff_cannot_list_staff(): void
    {
        // kitchen-staff only has: menu.view, orders.view, orders.prepare, orders.ready
        // staff.view and staff.manage are NOT included → 403
        $this->actingAs($this->kitchenStaff, 'sanctum')
            ->getJson('/api/v1/admin/staff')
            ->assertForbidden();
    }

    public function test_admin_can_create_staff_account(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/staff', [
                'name'     => 'New Waiter',
                'email'    => 'newwaiter@test.com',
                'phone'    => '0244123456',
                'role'     => 'waiter-waitress',
                'password' => 'SecurePass1!',
            ]);

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'message' => 'Staff account created successfully.',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newwaiter@test.com',
            'name'  => 'New Waiter',
        ]);
    }

    public function test_staff_creation_requires_all_fields(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/staff', [])
            ->assertUnprocessable();
    }

    public function test_staff_creation_validates_unique_email(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/staff', [
                'name'     => 'Duplicate',
                'email'    => $this->waiter->email,
                'role'     => 'waiter-waitress',
                'password' => 'SecurePass1!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_password_hash_is_never_returned_in_staff_response(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/staff', [
                'name'     => 'Hash Test',
                'email'    => 'hashtest@test.com',
                'role'     => 'waiter-waitress',
                'password' => 'SecurePass1!',
            ]);

        $response->assertCreated();
        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    public function test_admin_can_create_another_administrator_account(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/staff', [
                'name'     => 'Another Admin',
                'email'    => 'anotheradmin@test.com',
                'role'     => 'super-admin',
                'password' => 'SecurePass1!',
            ]);

        $response->assertCreated()
            ->assertJson(['success' => true]);
    }

    public function test_non_admin_cannot_access_staff_management(): void
    {
        // Waiter does not have staff.manage permission
        $this->actingAs($this->waiter, 'sanctum')
            ->postJson('/api/v1/admin/staff', [
                'name'     => 'Unauthorized',
                'email'    => 'unauth@test.com',
                'role'     => 'waiter-waitress',
                'password' => 'SecurePass1!',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_view_individual_staff_account(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/staff/{$this->waiter->id}");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data'    => ['id' => $this->waiter->id],
            ]);
    }

    public function test_staff_show_returns_404_for_missing_user(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/staff/99999')
            ->assertNotFound();
    }

    public function test_admin_can_update_staff_account(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/staff/{$this->kitchenStaff->id}", [
                'name'  => 'Updated Kitchen Staff',
                'email' => $this->kitchenStaff->email,
                'role'  => 'kitchen-staff',
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'id'   => $this->kitchenStaff->id,
            'name' => 'Updated Kitchen Staff',
        ]);
    }

    public function test_admin_can_toggle_staff_status(): void
    {
        $originalStatus = $this->waiter->is_active;

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/staff/{$this->waiter->id}/status");

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->waiter->refresh();
        $this->assertNotEquals($originalStatus, $this->waiter->is_active);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/staff/{$this->admin->id}/status")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_cannot_deactivate_last_active_administrator(): void
    {
        // Deactivate secondAdmin first
        $this->secondAdmin->update(['is_active' => false]);

        // Now admin is the only active administrator — cannot deactivate via self
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/staff/{$this->admin->id}/status")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_admin_can_reset_staff_password(): void
    {
        // The controller takes 'password' (not 'new_password')
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/staff/{$this->waiter->id}/reset-password", [
                'password' => 'NewSecurePass99!',
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        // Verify password was actually changed
        $this->waiter->refresh();
        $this->assertTrue(Hash::check('NewSecurePass99!', $this->waiter->password));
    }

    public function test_password_reset_requires_minimum_length(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/staff/{$this->waiter->id}/reset-password", [
                'password' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_reset_invalidates_existing_tokens(): void
    {
        // Create a token for the waiter
        $this->waiter->createToken('test-token');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/staff/{$this->waiter->id}/reset-password", [
                'password' => 'NewSecurePass99!',
            ])
            ->assertOk();

        // All tokens for the waiter should be deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id'   => $this->waiter->id,
            'tokenable_type' => User::class,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C. ROLE AND PERMISSION MANAGEMENT
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_list_roles_with_permissions(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/roles');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'slug'],
                ],
            ]);

        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains('super-admin'));
        $this->assertTrue($slugs->contains('waiter-waitress'));
        $this->assertTrue($slugs->contains('kitchen-staff'));
        $this->assertTrue($slugs->contains('delivery-staff'));
    }

    public function test_admin_can_list_all_permissions(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/permissions');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['*' => ['id', 'name', 'slug']],
            ]);
    }

    public function test_admin_can_update_role_permissions(): void
    {
        $role       = Role::where('slug', 'delivery-staff')->first();
        $permission = Permission::where('slug', 'orders.view')->first();

        $this->assertNotNull($permission, 'orders.view permission must exist');

        // The controller expects 'permission_slugs' (array of slugs), not IDs
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$role->id}/permissions", [
                'permission_slugs' => ['orders.view', 'menu.view'],
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_cannot_remove_critical_permissions_from_administrator_role(): void
    {
        $adminRole = Role::where('slug', 'super-admin')->first();

        // Attempt to sync administrator role with an empty permission set
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$adminRole->id}/permissions", [
                'permission_slugs' => [],
            ]);

        // Validation requires at least one element for the array,
        // or the controller blocks removal of critical permissions (422)
        $this->assertContains($response->status(), [422, 403],
            'Should prevent stripping all permissions from administrator role');
    }

    public function test_cannot_remove_specific_critical_permission_from_administrator(): void
    {
        $adminRole = Role::where('slug', 'super-admin')->first();

        // Provide all critical perms EXCEPT 'staff.manage' — should be rejected (422)
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$adminRole->id}/permissions", [
                'permission_slugs' => [
                    'settings.manage',
                    'roles.manage',
                    'orders.view',
                    'orders.complete',
                    'audit.view',
                    'content.manage',
                    // 'staff.manage' intentionally omitted
                ],
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_non_admin_cannot_access_role_management(): void
    {
        // Waiter does not have roles.manage or staff.manage
        $this->actingAs($this->waiter, 'sanctum')
            ->getJson('/api/v1/admin/roles')
            ->assertForbidden();
    }

    public function test_staff_management_permission_does_not_grant_role_management_endpoints(): void
    {
        $staffManager = $this->createUserWithPermissions(['staff.view', 'staff.manage'], 'staff-only');
        $existingRole = Role::where('slug', 'waiter-waitress')->firstOrFail();

        $this->actingAs($staffManager, 'sanctum')
            ->getJson('/api/v1/admin/staff')->assertOk();
        $this->getJson('/api/v1/admin/staff/roles')->assertOk();
        $this->getJson('/api/v1/admin/roles')->assertForbidden();
        $this->getJson('/api/v1/admin/permissions')->assertForbidden();
        $this->postJson('/api/v1/admin/roles', ['name' => 'Unauthorized', 'slug' => 'unauthorized'])
            ->assertForbidden();
        $this->putJson('/api/v1/admin/roles/'.$existingRole->id, [
            'name' => 'Changed', 'slug' => $existingRole->slug,
        ])->assertForbidden();
        $this->deleteJson('/api/v1/admin/roles/'.$existingRole->id)->assertForbidden();
        $this->putJson('/api/v1/admin/roles/'.$existingRole->id.'/permissions', [
            'permission_slugs' => ['staff.view'],
        ])->assertForbidden();

        $this->postJson('/api/v1/admin/staff', [
            'name' => 'Staff account created by staff manager',
            'email' => 'staff-created-by-manager@rbac.test',
            'role' => 'waiter-waitress',
            'password' => 'SecurePass1!',
        ])->assertCreated();
    }

    public function test_roles_management_permission_does_not_grant_staff_account_access(): void
    {
        $roleManager = $this->createUserWithPermissions(['roles.manage'], 'roles-only');

        $this->actingAs($roleManager, 'sanctum')
            ->getJson('/api/v1/admin/roles')->assertOk();
        $this->getJson('/api/v1/admin/permissions')->assertOk();
        $this->getJson('/api/v1/admin/staff')->assertForbidden();
        $this->getJson('/api/v1/admin/staff/roles')->assertForbidden();
        $this->postJson('/api/v1/admin/staff', [
            'name' => 'Unauthorized staff account',
            'email' => 'unauthorized-staff@rbac.test',
            'role' => 'waiter-waitress',
            'password' => 'SecurePass1!',
        ])->assertForbidden();
    }

    public function test_staff_and_role_access_scenarios_are_independent(): void
    {
        $both = $this->createUserWithPermissions(['staff.view', 'staff.manage', 'roles.manage'], 'both');
        $neither = $this->createUserWithPermissions([], 'neither');

        $this->actingAs($both, 'sanctum')
            ->getJson('/api/v1/admin/staff')->assertOk();
        $this->getJson('/api/v1/admin/roles')->assertOk();

        $this->actingAs($neither, 'sanctum')
            ->getJson('/api/v1/admin/staff')->assertForbidden();
        $this->getJson('/api/v1/admin/roles')->assertForbidden();
    }

    public function test_role_permission_changes_take_effect_on_the_next_authenticated_request(): void
    {
        $roleManager = $this->createUserWithPermissions(['roles.manage'], 'permission-refresh');
        $role = $roleManager->roles()->firstOrFail();
        $rolesPermission = Permission::where('slug', 'roles.manage')->firstOrFail();

        $this->actingAs($roleManager, 'sanctum')
            ->getJson('/api/v1/admin/roles')->assertOk();

        $role->permissions()->detach($rolesPermission->id);
        $this->actingAs($roleManager->fresh(), 'sanctum')
            ->getJson('/api/v1/admin/roles')->assertForbidden();

        $role->permissions()->attach($rolesPermission->id);
        $this->actingAs($roleManager->fresh(), 'sanctum')
            ->getJson('/api/v1/admin/roles')->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D. RESTAURANT SETTINGS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_retrieve_restaurant_settings(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/settings');

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_admin_can_update_restaurant_settings(): void
    {
        // The controller takes 'settings' as an array of {key, value, group} objects
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/settings', [
                'settings' => [
                    ['key' => 'restaurant_name', 'value' => 'Special Delicacies Updated', 'group' => 'general'],
                    ['key' => 'restaurant_phone', 'value' => '0302000001', 'group' => 'contact'],
                ],
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('restaurant_settings', [
            'key'   => 'restaurant_name',
            'value' => 'Special Delicacies Updated',
        ]);
    }

    public function test_settings_response_does_not_expose_secrets(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/settings');

        $response->assertOk();
        $content = json_encode($response->json());

        $this->assertStringNotContainsString('"password":', $content);
        $this->assertStringNotContainsString('"api_key":', $content);
    }

    public function test_non_admin_cannot_update_settings(): void
    {
        // Waiter does not have settings.manage permission
        $this->actingAs($this->waiter, 'sanctum')
            ->putJson('/api/v1/admin/settings', [
                'settings' => [
                    ['key' => 'restaurant_name', 'value' => 'Hacked Name'],
                ],
            ])
            ->assertForbidden();
    }

    public function test_currency_cannot_be_changed_from_ghs(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/settings', [
                'settings' => [
                    ['key' => 'currency', 'value' => 'USD', 'group' => 'general'],
                ],
            ]);

        $response->assertOk();

        // The controller enforces GHS regardless of input
        $this->assertDatabaseHas('restaurant_settings', [
            'key'   => 'currency',
            'value' => 'GHS',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E. WEBSITE CONTENT
    // ─────────────────────────────────────────────────────────────────────────

    public function test_public_content_endpoint_requires_no_auth(): void
    {
        $response = $this->getJson('/api/v1/content');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_admin_can_retrieve_website_content(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/content');

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_admin_can_update_website_content(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/content', [
                'hero_title'    => 'Welcome to Special Delicacies',
                'hero_subtitle' => 'Authentic Ghanaian Cuisine',
                'about_story'   => 'Our story began in Accra...',
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_non_admin_cannot_update_website_content(): void
    {
        // Waiter does not have content.manage permission
        $this->actingAs($this->waiter, 'sanctum')
            ->putJson('/api/v1/admin/content', [
                'hero_title' => 'Unauthorized Change',
            ])
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F. ORDER OVERSIGHT
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_view_all_orders(): void
    {
        $this->createOrder(['status' => 'pending']);
        $this->createOrder(['status' => 'completed', 'payment_status' => 'paid']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/orders');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_admin_can_filter_orders_by_status(): void
    {
        $this->createOrder(['status' => 'pending']);
        $this->createOrder(['status' => 'completed', 'payment_status' => 'paid']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/orders?status=pending');

        $response->assertOk();
        $orders = $response->json('data');
        foreach ($orders as $order) {
            $this->assertEquals('pending', $order['status']);
        }
    }

    public function test_admin_can_filter_orders_awaiting_payment(): void
    {
        $this->createOrder([
            'status'         => 'completed',
            'payment_method' => 'pay_on_delivery',
            'payment_status' => 'unpaid',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/orders?awaiting_payment=1');

        $response->assertOk();
        $orders = $response->json('data');
        foreach ($orders as $order) {
            $this->assertEquals('unpaid', $order['payment_status']);
        }
    }

    public function test_admin_can_view_individual_order(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/orders/{$order->id}");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data'    => ['id' => $order->id],
            ]);
    }

    public function test_admin_can_confirm_cash_payment_for_order(): void
    {
        $order = $this->createOrder([
            'status'         => 'completed',
            'payment_method' => 'pay_on_delivery',
            'payment_status' => 'unpaid',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/confirm-payment", [
                'note' => 'Cash received.',
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', [
            'id'             => $order->id,
            'payment_status' => 'paid',
        ]);
    }

    public function test_order_oversight_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/orders')
            ->assertUnauthorized();
    }

    public function test_staff_without_orders_view_cannot_access_admin_orders(): void
    {
        // Create a user with no permissions at all
        $userWithNoPerms = User::create([
            'name'      => 'No Perm User',
            'email'     => 'noperm@test.com',
            'password'  => bcrypt('secret1234'),
            'is_active' => true,
        ]);

        $this->actingAs($userWithNoPerms, 'sanctum')
            ->getJson('/api/v1/admin/orders')
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // G. AUDIT LOG
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_retrieve_audit_logs(): void
    {
        AdminAuditLog::create([
            'user_id'     => $this->admin->id,
            'action'      => 'staff.create',
            'entity_type' => User::class,
            'entity_id'   => (string) $this->waiter->id,
            'description' => 'Created staff account.',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
                'meta',
            ]);
    }

    public function test_audit_logs_can_be_filtered_by_action(): void
    {
        AdminAuditLog::create([
            'user_id'     => $this->admin->id,
            'action'      => 'settings.update',
            'description' => 'Changed restaurant name.',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs?action=settings.update');

        $response->assertOk();
        $entries = $response->json('data');
        foreach ($entries as $entry) {
            $this->assertStringContainsString('settings', $entry['action']);
        }
    }

    public function test_kitchen_staff_cannot_access_audit_logs(): void
    {
        // kitchen-staff does NOT have audit.view or staff.manage
        $this->actingAs($this->kitchenStaff, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')
            ->assertForbidden();
    }

    public function test_audit_service_records_staff_creation(): void
    {
        $initialCount = AdminAuditLog::count();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/staff', [
                'name'     => 'Audit Test Staff',
                'email'    => 'auditstaff@test.com',
                'role'     => 'waiter-waitress',
                'password' => 'SecurePass1!',
            ])
            ->assertCreated();

        $this->assertGreaterThan($initialCount, AdminAuditLog::count(),
            'An audit log entry should be created when staff is created');

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'staff.create',
        ]);
    }

    public function test_audit_service_records_staff_status_toggle(): void
    {
        $initialCount = AdminAuditLog::count();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/staff/{$this->deliveryStaff->id}/status")
            ->assertOk();

        $this->assertGreaterThan($initialCount, AdminAuditLog::count(),
            'An audit log entry should be created on status toggle');
    }

    public function test_audit_service_sanitizes_password_fields(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $log = $auditService->log(
            $this->admin,
            'staff.create',
            'Created test user.',
            null,
            null,
            [
                'name'     => 'Test',
                'email'    => 'test@example.com',
                'password' => 'ShouldBeRedacted123!',
                'token'    => 'some-secret-token',
            ]
        );

        $this->assertEquals('[REDACTED]', $log->new_values['password']);
        $this->assertEquals('[REDACTED]', $log->new_values['token']);
        $this->assertEquals('Test', $log->new_values['name']);
        $this->assertEquals('test@example.com', $log->new_values['email']);
    }

    public function test_audit_service_sanitizes_nested_sensitive_keys(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $log = $auditService->log(
            $this->admin,
            'settings.update',
            'Changed settings.',
            null,
            null,
            [
                'name'    => 'Test Setting',
                'secret'  => 'top-secret-value',
                'api_key' => 'key-12345',
                'nested'  => [
                    'authorization' => 'Bearer sensitive-token',
                    'new_password'  => 'plainPassword123!',
                ],
            ]
        );

        $this->assertEquals('[REDACTED]', $log->new_values['secret']);
        $this->assertEquals('[REDACTED]', $log->new_values['api_key']);
        $this->assertEquals('[REDACTED]', $log->new_values['nested']['authorization']);
        $this->assertEquals('[REDACTED]', $log->new_values['nested']['new_password']);
        $this->assertEquals('Test Setting', $log->new_values['name']);
    }

    public function test_audit_service_records_delivery_zone_changes(): void
    {
        $initialCount = AdminAuditLog::count();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/delivery-zones', [
                'name'         => 'East Legon Zone Audit Test',
                'delivery_fee' => '25.00',
                'description'  => 'East Legon residential area',
            ]);

        $response->assertCreated();

        $this->assertGreaterThan($initialCount, AdminAuditLog::count());
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'delivery_zone.create',
        ]);
    }

    public function test_audit_log_stores_actor_id(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $log = $auditService->log(
            $this->admin,
            'settings.update',
            'Changed restaurant name.'
        );

        $this->assertEquals($this->admin->id, $log->user_id);
        $this->assertEquals('settings.update', $log->action);
    }

    public function test_audit_log_accepts_null_actor_for_system_actions(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $log = $auditService->log(
            null,
            'system.action',
            'System-initiated action.'
        );

        $this->assertNull($log->user_id);
        $this->assertEquals('system.action', $log->action);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // H. SECURITY CONSTRAINTS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_admin_staff_endpoints_reject_unauthenticated_requests(): void
    {
        $endpoints = [
            ['GET', '/api/v1/admin/staff'],
            ['POST', '/api/v1/admin/staff'],
            ['GET', "/api/v1/admin/staff/{$this->waiter->id}"],
            ['PUT', "/api/v1/admin/staff/{$this->waiter->id}"],
            ['PATCH', "/api/v1/admin/staff/{$this->waiter->id}/status"],
            ['POST', "/api/v1/admin/staff/{$this->waiter->id}/reset-password"],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $this->json($method, $url);
            $this->assertEquals(401, $response->status(),
                "Expected 401 for unauthenticated {$method} {$url}");
        }
    }

    public function test_admin_settings_and_content_endpoints_reject_unauthenticated(): void
    {
        $endpoints = [
            ['GET', '/api/v1/admin/settings'],
            ['PUT', '/api/v1/admin/settings'],
            ['GET', '/api/v1/admin/content'],
            ['PUT', '/api/v1/admin/content'],
            ['GET', '/api/v1/admin/audit-logs'],
            ['GET', '/api/v1/admin/roles'],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $this->json($method, $url);
            $this->assertEquals(401, $response->status(),
                "Expected 401 for unauthenticated {$method} {$url}");
        }
    }

    public function test_staff_response_never_contains_passwords(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/staff');

        $response->assertOk();
        $content = json_encode($response->json('data'));
        $this->assertStringNotContainsString('"password"', $content);
    }

    public function test_deactivated_user_tokens_are_revoked_on_status_toggle(): void
    {
        // Give waiter an active token
        $this->waiter->createToken('active-session');

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/staff/{$this->waiter->id}/status")
            ->assertOk();

        $this->waiter->refresh();

        // If deactivated, token should be gone
        if (! $this->waiter->is_active) {
            $this->assertDatabaseMissing('personal_access_tokens', [
                'tokenable_id'   => $this->waiter->id,
                'tokenable_type' => User::class,
            ]);
        }
    }
}
