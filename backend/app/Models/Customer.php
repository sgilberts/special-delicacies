<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function organisationMemberships(): HasMany
    {
        return $this->hasMany(OrganisationMembership::class);
    }

    public function organisationInvitations(): HasMany
    {
        return $this->hasMany(OrganisationInvitation::class);
    }

    public function commercialDocuments(): HasMany
    {
        return $this->hasMany(CommercialDocument::class);
    }

    public function creditLedgerEntries(): HasMany
    {
        return $this->hasMany(CustomerCreditLedgerEntry::class);
    }
}
