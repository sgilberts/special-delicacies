<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug', 'description'])]
class Role extends Model
{
    use HasFactory;

    /**
     * The users that belong to the role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')->withTimestamps();
    }

    /**
     * The permissions that belong to the role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')->withTimestamps();
    }

    /**
     * Assign a permission to the role.
     */
    public function givePermissionTo(Permission|string $permission): void
    {
        if (is_string($permission)) {
            $permission = Permission::firstOrCreate(
                ['slug' => $permission],
                ['name' => ucwords(str_replace(['.', '_', '-'], ' ', $permission))]
            );
        }

        $this->permissions()->syncWithoutDetaching([$permission->id]);
    }

    /**
     * Check if role has a permission.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        return $this->permissions->contains('slug', $permissionSlug);
    }
}
