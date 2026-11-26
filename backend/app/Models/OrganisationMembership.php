<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organisation_id', 'customer_id', 'role', 'status', 'invited_by', 'joined_at', 'approved_at'])]
class OrganisationMembership extends Model
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMINISTRATOR = 'administrator';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_MEMBER = 'member';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REMOVED = 'removed';

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'invited_by');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(OrganisationMembershipPermission::class);
    }
}
