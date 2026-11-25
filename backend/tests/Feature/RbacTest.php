<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
