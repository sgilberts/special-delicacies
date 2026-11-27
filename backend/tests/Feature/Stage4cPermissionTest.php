<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
