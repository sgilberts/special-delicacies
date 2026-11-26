<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organisation_membership_id', 'permission', 'granted_by_customer_id', 'granted_at'])]
class OrganisationMembershipPermission extends Model
{
    protected function casts(): array
    {
        return ['granted_at' => 'datetime'];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganisationMembership::class, 'organisation_membership_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'granted_by_customer_id');
    }
}
