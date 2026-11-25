<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'restaurant_table_id',
    'token_hash',
    'is_active',
    'generated_by',
    'revoked_at',
])]
class TableQrCode extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Restaurant table associated with this QR code.
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    /**
     * User who generated this QR code.
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Scope to active QR codes.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('revoked_at');
    }
}
