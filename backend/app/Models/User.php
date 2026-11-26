<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'profile_photo_path', 'is_active', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * The roles that belong to the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    /**
     * Check if user has a specific role by slug or name.
     */
    public function hasRole(string|array $roles): bool
    {
        $this->loadMissing('roles');
        $roles = (array) $roles;

        return $this->roles->contains(function ($role) use ($roles) {
            return in_array($role->slug, $roles, true) || in_array($role->name, $roles, true);
        });
    }

    /**
     * Check if user has any of the given roles.
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->hasRole($roles);
    }

    /**
     * Check if user has a specific permission by slug.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        // Super Admin intrinsically owns every current and future permission.
        if ($this->hasRole('super-admin')) {
            return true;
        }

        $this->loadMissing('roles.permissions');

        return $this->roles->flatMap(fn ($role) => $role->permissions)->contains('slug', $permissionSlug);
    }

    /**
     * Check if user has any of the given permissions.
     */
    public function hasAnyPermission(array $permissionSlugs): bool
    {
        if ($this->hasRole('super-admin')) {
            return true;
        }

        $this->loadMissing('roles.permissions');

        $userPermissions = $this->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('slug')
            ->all();

        return ! empty(array_intersect($permissionSlugs, $userPermissions));
    }

    /**
     * Return the staff permissions effective for this user.
     *
     * Super Admin's implicit access is represented by the server-side staff
     * permission catalogue. Organisation membership permissions are stored
     * separately and are intentionally not included here.
     *
     * @return list<string>
     */
    public function effectivePermissionSlugs(): array
    {
        if ($this->hasRole('super-admin')) {
            return Permission::query()->orderBy('slug')->pluck('slug')->all();
        }

        $this->loadMissing('roles.permissions');

        return $this->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('slug')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
