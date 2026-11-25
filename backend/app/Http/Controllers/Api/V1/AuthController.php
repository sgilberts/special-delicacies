<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Models\ChatAgentPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * Authenticate administrative user and return Sanctum Bearer token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is deactivated. Contact the Super Admin.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Load roles and permissions
        $user->loadMissing(['roles.permissions']);
        $roles = $user->roles->pluck('slug')->all();
        $permissions = $user->roles->flatMap(fn ($role) => $role->permissions)->pluck('slug')->unique()->values()->all();

        // Staff accounts with an assigned operational role may use the staff console.
        if ($user->roles()->doesntExist()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. This account has no assigned staff role.',
            ], Response::HTTP_FORBIDDEN);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Authenticated successfully.',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'profile_photo_url' => $user->profile_photo_path ? asset('storage/'.$user->profile_photo_path) : null,
                    'is_active' => $user->is_active,
                    'roles' => $roles,
                    'permissions' => $permissions,
                ],
            ],
        ]);
    }

    /**
     * Return current authenticated user profile and roles.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing(['roles.permissions']);

        $roles = $user->roles->pluck('slug')->all();
        $permissions = $user->roles->flatMap(fn ($role) => $role->permissions)->pluck('slug')->unique()->values()->all();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'profile_photo_url' => $user->profile_photo_path ? asset('storage/'.$user->profile_photo_path) : null,
                'is_active' => $user->is_active,
                'roles' => $roles,
                'permissions' => $permissions,
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'unique:users,phone,'.$user->id],
            'current_password' => ['required_with:new_password', 'string'],
            'new_password' => ['sometimes', 'required', 'string', 'min:8', 'confirmed'],
            'profile_photo' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (isset($validated['new_password']) && ! Hash::check($validated['current_password'] ?? '', $user->password)) {
            return response()->json(['success' => false, 'message' => 'The current password is incorrect.', 'errors' => ['current_password' => ['The current password is incorrect.']]], 422);
        }

        foreach (['name', 'email', 'phone'] as $field) {
            if (array_key_exists($field, $validated)) $user->{$field} = $validated[$field];
        }
        if (isset($validated['new_password'])) $user->password = $validated['new_password'];
        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo_path) \Illuminate\Support\Facades\Storage::disk('public')->delete($user->profile_photo_path);
            $user->profile_photo_path = $request->file('profile_photo')->store('user-avatars', 'public');
        }
        $user->save();

        return response()->json(['success' => true, 'message' => 'Profile updated successfully.', 'data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
            'is_active' => $user->is_active, 'profile_photo_url' => $user->profile_photo_path ? asset('storage/'.$user->profile_photo_path) : null,
            'roles' => $user->roles->pluck('slug')->all(),
            'permissions' => $user->roles->flatMap(fn ($role) => $role->permissions)->pluck('slug')->unique()->values()->all(),
        ]]);
    }

    /**
     * Log out authenticated user by revoking current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && $user->hasPermission('chat.receive')) {
            ChatAgentPresence::updateOrCreate(['user_id' => $user->id], ['status' => 'offline', 'last_seen_at' => now()]);
        }

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }
}
