<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminStaffController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * List all staff accounts with filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with('roles:id,name,slug');

        if ($request->has('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $roleSlug = $request->input('role');
            $query->whereHas('roles', function ($q) use ($roleSlug) {
                $q->where('slug', $roleSlug);
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        $staff = $query->orderBy('name')->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $staff->items(),
            'meta' => [
                'current_page' => $staff->currentPage(),
                'last_page' => $staff->lastPage(),
                'per_page' => $staff->perPage(),
                'total' => $staff->total(),
            ],
        ]);
    }

    /** Return only the role identifiers and labels this actor may assign. */
    public function assignableRoles(Request $request): JsonResponse
    {
        $roles = Role::query()
            ->when(! $request->user()->hasRole('super-admin'), fn ($query) => $query->where('slug', '<>', 'super-admin'))
            ->orderBy('name')
            ->get(['id', 'slug', 'name']);

        return response()->json(['success' => true, 'data' => $roles]);
    }

    /**
     * Store a newly created staff member.
     */
    public function store(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone'    => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'role'     => ['required', 'string', 'exists:roles,slug'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        // Privilege escalation guard: Only Super Admin can assign the Super Admin role.
        if ($validated['role'] === 'super-admin' && ! $currentUser->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized privilege escalation. Only Super Admin can assign the Super Admin role.',
            ], 403);
        }

        $user = DB::transaction(function () use ($validated, $currentUser, $request) {
            $user = User::create([
                'name'      => $validated['name'],
                'email'     => strtolower(trim($validated['email'])),
                'phone'     => $validated['phone'] ?? null,
                'password'  => Hash::make($validated['password']),
                'is_active' => true,
            ]);

            $role = Role::where('slug', $validated['role'])->firstOrFail();
            $user->roles()->sync([$role->id]);

            $this->auditService->log(
                $currentUser,
                'staff.create',
                "Created staff account {$user->name} ({$user->email}) with role {$role->name}.",
                $user,
                null,
                [
                    'name'      => $user->name,
                    'email'     => $user->email,
                    'phone'     => $user->phone,
                    'role'      => $role->slug,
                    'is_active' => true,
                ],
                $request
            );

            return $user->load('roles:id,name,slug');
        });

        return response()->json([
            'success' => true,
            'message' => 'Staff account created successfully.',
            'data'    => $user,
        ], 201);
    }

    /**
     * Display the specified staff account.
     */
    public function show(int $id): JsonResponse
    {
        $user = User::with(['roles.permissions'])->find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Staff account not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $user,
        ]);
    }

    /**
     * Update the specified staff account.
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $targetUser = User::with('roles')->find($id);

        if (! $targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'Staff account not found.',
            ], 404);
        }

        if ($targetUser->hasRole('super-admin') && ! $currentUser->hasRole('super-admin')) {
            return response()->json(['success' => false, 'message' => 'Only a Super Admin may modify a protected Super Admin account.'], 403);
        }

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($targetUser->id)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($targetUser->id)],
            'role'  => ['nullable', 'string', 'exists:roles,slug'],
        ]);

        // Guard against removing last active Super Admin
        if (isset($validated['role']) && $validated['role'] !== 'super-admin' && $targetUser->hasRole('super-admin')) {
            $activeAdminCount = User::where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('slug', 'super-admin'))
                ->count();

            if ($activeAdminCount <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot remove Super Admin role from the only active Super Admin.',
                ], 422);
            }
        }

        // Privilege escalation guard
        if (isset($validated['role']) && $validated['role'] === 'super-admin' && ! $currentUser->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized privilege escalation. Only Super Admin can assign the Super Admin role.',
            ], 403);
        }

        $oldRole = $targetUser->roles->first()?->slug;
        $oldValues = [
            'name'  => $targetUser->name,
            'email' => $targetUser->email,
            'phone' => $targetUser->phone,
            'role'  => $oldRole,
        ];

        DB::transaction(function () use ($targetUser, $validated, $currentUser, $oldValues, $request) {
            $targetUser->update([
                'name'  => $validated['name'],
                'email' => strtolower(trim($validated['email'])),
                'phone' => $validated['phone'] ?? null,
            ]);

            if (isset($validated['role'])) {
                $role = Role::where('slug', $validated['role'])->firstOrFail();
                $targetUser->roles()->sync([$role->id]);
            }

            $newRole = $targetUser->fresh()->roles->first()?->slug;
            $newValues = [
                'name'  => $targetUser->name,
                'email' => $targetUser->email,
                'phone' => $targetUser->phone,
                'role'  => $newRole,
            ];

            $this->auditService->log(
                $currentUser,
                'staff.update',
                "Updated staff account {$targetUser->name} ({$targetUser->email}).",
                $targetUser,
                $oldValues,
                $newValues,
                $request
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Staff account updated successfully.',
            'data'    => $targetUser->fresh(['roles:id,name,slug']),
        ]);
    }

    /**
     * Toggle staff active status (activation / deactivation).
     */
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $targetUser = User::with('roles')->find($id);

        if (! $targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'Staff account not found.',
            ], 404);
        }

        if ($targetUser->hasRole('super-admin') && ! $currentUser->hasRole('super-admin')) {
            return response()->json(['success' => false, 'message' => 'Only a Super Admin may change a protected Super Admin account.'], 403);
        }

        // Prevent self-deactivation
        if ($currentUser->id === $targetUser->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        // Protect last active Super Admin
        if ($targetUser->is_active && $targetUser->hasRole('super-admin')) {
            $activeAdminCount = User::where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('slug', 'super-admin'))
                ->count();

            if ($activeAdminCount <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot deactivate the last active Super Admin.',
                ], 422);
            }
        }

        $oldStatus = $targetUser->is_active;
        $newStatus = ! $oldStatus;

        DB::transaction(function () use ($targetUser, $newStatus, $currentUser, $request) {
            $targetUser->update(['is_active' => $newStatus]);

            // If deactivated, revoke all active sessions immediately
            if (! $newStatus) {
                $targetUser->tokens()->delete();
            }

            $action = $newStatus ? 'staff.activate' : 'staff.deactivate';
            $verb = $newStatus ? 'Activated' : 'Deactivated';

            $this->auditService->log(
                $currentUser,
                $action,
                "{$verb} staff account {$targetUser->name} ({$targetUser->email}).",
                $targetUser,
                ['is_active' => ! $newStatus],
                ['is_active' => $newStatus],
                $request
            );
        });

        $message = $newStatus ? 'Staff account activated successfully.' : 'Staff account deactivated successfully.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $targetUser->fresh(['roles:id,name,slug']),
        ]);
    }

    /**
     * Reset staff password securely.
     */
    public function resetPassword(int $id, Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $targetUser = User::find($id);

        if (! $targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'Staff account not found.',
            ], 404);
        }

        if ($targetUser->hasRole('super-admin') && ! $currentUser->hasRole('super-admin')) {
            return response()->json(['success' => false, 'message' => 'Only a Super Admin may change a protected Super Admin account.'], 403);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        DB::transaction(function () use ($targetUser, $validated, $currentUser, $request) {
            $targetUser->update([
                'password' => Hash::make($validated['password']),
            ]);

            // Invalidate existing sessions for this user
            $targetUser->tokens()->delete();

            $this->auditService->log(
                $currentUser,
                'staff.password_reset',
                "Reset password for staff account {$targetUser->name} ({$targetUser->email}).",
                $targetUser,
                null,
                null,
                $request
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Staff password reset successfully. Active sessions for this user have been invalidated.',
        ]);
    }
}
