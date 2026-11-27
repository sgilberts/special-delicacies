<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['reference', 'owner_customer_id', 'name', 'description', 'business_email', 'business_phone', 'address', 'status'])]
class Organisation extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected function casts(): array
    {
        return [
            // Not fillable: only the archive/restore lifecycle writes this.
            'archived_at' => 'datetime',
        ];
    }

    /** An archived organisation keeps every membership and document record. */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'owner_customer_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganisationMembership::class);
    }

    public function creditLedgerEntries(): HasMany
    {
        return $this->hasMany(CustomerCreditLedgerEntry::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganisationInvitation::class);
    }

    public function commercialDocuments(): HasMany
    {
        return $this->hasMany(CommercialDocument::class);
    }
}
