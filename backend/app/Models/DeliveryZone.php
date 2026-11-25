<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'delivery_fee', 'is_active'])]
class DeliveryZone extends Model
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
            'delivery_fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Orders placed in this delivery zone.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'delivery_zone_id');
    }

    /**
     * Scope to active delivery zones.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
