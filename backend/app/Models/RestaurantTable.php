<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['table_number', 'capacity', 'is_active'])]
class RestaurantTable extends Model
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
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * All QR codes issued for this table.
     */
    public function qrCodes(): HasMany
    {
        return $this->hasMany(TableQrCode::class, 'restaurant_table_id');
    }

    /**
     * The single currently active QR code for this table.
     */
    public function activeQrCode(): HasOne
    {
        return $this->hasOne(TableQrCode::class, 'restaurant_table_id')
            ->where('is_active', true)
            ->latestOfMany();
    }

    /**
     * Orders placed at this table.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'restaurant_table_id');
    }

    /**
     * Scope to active tables.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
