<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organisation_id', 'customer_id', 'invited_by', 'status', 'expires_at', 'accepted_at', 'cancelled_at'])]
class OrganisationInvitation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

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
}
