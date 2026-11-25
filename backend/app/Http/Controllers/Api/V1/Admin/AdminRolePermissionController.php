<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class AdminRolePermissionController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * List all roles with their assigned permissions and user counts.
     */
    public function index(): JsonResponse
    {
        $roles = Role::with(['permissions'])
            ->withCount('users')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $roles,
        ]);
    }

    /**
     * List all permissions in the system, grouped by module/category.
     */
    public function permissions(): JsonResponse
    {
        $permissions = Permission::all()->map(function ($perm) {
            $prefix = explode('.', $perm->slug)[0];
            $group = match ($prefix) {
                'menu'           => 'Menu Management',
                'orders'         => 'Order Operations',
                'tables'         => 'Tables & QR Codes',
                'delivery'       => 'Delivery Operations',
                'delivery_zones' => 'Delivery Zones',
                'settings'       => 'Restaurant Settings',
                'staff'          => 'Staff & Security',
                'roles'          => 'Roles & Permissions',
                'content'        => 'Website Content',
                'audit'          => 'Audit Trail',
                'financials'     => 'Financial Management',
                default          => 'General',
            };

            return [
                'id'          => $perm->id,
                'name'        => $perm->name,
                'slug'        => $perm->slug,
                'description' => $perm->description,
                'group'       => $group,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $permissions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:roles,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_slugs' => ['sometimes', 'array'],
            'permission_slugs.*' => ['string', 'exists:permissions,slug'],
        ]);
        $validated['slug'] = Str::slug($validated['slug']);

        $role = DB::transaction(function () use ($validated, $request) {
            $role = Role::create(collect($validated)->only(['name', 'slug', 'description'])->all());
            $permissions = Permission::whereIn('slug', $validated['permission_slugs'] ?? [])->pluck('id');
            $role->permissions()->sync($permissions);
            $this->auditService->log($request->user(), 'role.create', "Created role {$role->name}.", $role, null,
                ['name' => $role->name, 'slug' => $role->slug, 'permission_slugs' => $validated['permission_slugs'] ?? []], $request);
            return $role->load('permissions')->loadCount('users');
        });

        return response()->json(['success' => true, 'message' => 'Role created successfully.', 'data' => $role], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $role = Role::find($id);
        if (! $role) return response()->json(['success' => false, 'message' => 'Role not found.'], 404);
        if ($role->slug === 'super-admin') {
            return response()->json(['success' => false, 'message' => 'The Super Admin role identity is protected.'], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('roles', 'slug')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $validated['slug'] = Str::slug($validated['slug']);
        $before = $role->only(['name', 'slug', 'description']);
        $role->update($validated);
        $this->auditService->log($request->user(), 'role.update', "Updated role {$role->name}.", $role, $before,
            $role->only(['name', 'slug', 'description']), $request);

        return response()->json(['success' => true, 'message' => 'Role updated successfully.', 'data' => $role->fresh(['permissions'])->loadCount('users')]);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $role = Role::withCount('users')->find($id);
        if (! $role) return response()->json(['success' => false, 'message' => 'Role not found.'], 404);
        if ($role->slug === 'super-admin' || $role->users_count > 0) {
            return response()->json(['success' => false, 'message' => 'This role is protected or still assigned to staff. Reassign its users before deleting it.'], 422);
        }

        DB::transaction(function () use ($role, $request) {
            $before = $role->only(['name', 'slug', 'description']);
            $role->permissions()->detach();
            $role->delete();
            $this->auditService->log($request->user(), 'role.delete', "Deleted role {$role->name}.", $role, $before, null, $request);
        });

        return response()->json(['success' => true, 'message' => 'Role deleted successfully.']);
    }

    /**
     * Update permissions for a specific role safely.
     */
    public function updatePermissions(int $id, Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $role = Role::with('permissions')->find($id);

        if (! $role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found.',
            ], 404);
        }

        if ($role->slug === 'super-admin') {
            return response()->json([
                'success' => false,
                'message' => 'The Super Admin role has intrinsic full-system permission and cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'permission_slugs'   => ['required', 'array'],
            'permission_slugs.*' => ['required', 'string', 'exists:permissions,slug'],
        ]);

        $oldPermissions = $role->permissions->pluck('slug')->all();
        $newPermissionModels = Permission::whereIn('slug', $validated['permission_slugs'])->get();
        $newPermissionIds = $newPermissionModels->pluck('id')->all();

        DB::transaction(function () use ($role, $newPermissionIds, $currentUser, $oldPermissions, $validated, $request) {
            $role->permissions()->sync($newPermissionIds);

            $this->auditService->log(
                $currentUser,
                'role.permissions_update',
                "Updated permissions for role {$role->name}.",
                $role,
                ['permissions' => $oldPermissions],
                ['permissions' => $validated['permission_slugs']],
                $request
            );
        });

        return response()->json([
            'success' => true,
            'message' => "Permissions for role '{$role->name}' updated successfully.",
            'data'    => $role->fresh(['permissions']),
        ]);
    }
}
