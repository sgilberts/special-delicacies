<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'table_number',
    'venue_id',
    'capacity',
    'table_type',
    'is_active',
    'operational_status',
    'reservation_eligible',
    'seating_configuration',
    'admin_notes',
])]
class RestaurantTable extends Model
{
    use HasFactory;

    public const TYPE_STANDARD = 'standard';
    public const TYPE_BOOTH = 'booth';
    public const TYPE_BAR = 'bar';
    public const TYPE_PRIVATE = 'private';
    public const TYPE_OUTDOOR = 'outdoor';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_STANDARD,
        self::TYPE_BOOTH,
        self::TYPE_BAR,
        self::TYPE_PRIVATE,
        self::TYPE_OUTDOOR,
        self::TYPE_OTHER,
    ];

    /** Reservation-side operational state; `is_active` still governs QR ordering. */
    public const OPERATIONAL_AVAILABLE = 'available';
    public const OPERATIONAL_MAINTENANCE = 'maintenance';
    public const OPERATIONAL_OUT_OF_SERVICE = 'out_of_service';

    public const OPERATIONAL_STATUSES = [
        self::OPERATIONAL_AVAILABLE,
        self::OPERATIONAL_MAINTENANCE,
        self::OPERATIONAL_OUT_OF_SERVICE,
    ];

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
            'reservation_eligible' => 'boolean',
            'seating_configuration' => 'array',
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
     * Venue this physical table belongs to, when configured.
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * Reservation allocations that reference this physical table.
     */
    public function reservationAllocations(): HasMany
    {
        return $this->hasMany(ReservationTableAllocation::class, 'restaurant_table_id');
    }

    /**
     * Configured temporarily-unavailable / maintenance periods.
     */
    public function unavailabilityPeriods(): HasMany
    {
        return $this->hasMany(TableUnavailabilityPeriod::class, 'restaurant_table_id')->orderBy('starts_at');
    }

    /**
     * Scope to active tables.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to tables that may be offered to reservations.
     */
    public function scopeReservationEligible(Builder $query): Builder
    {
        return $query->where('reservation_eligible', true)
            ->where('operational_status', self::OPERATIONAL_AVAILABLE);
    }

    /**
     * D-5B: a dine-in order occupies its table until it reaches a terminal
     * status. This is a read-only occupancy signal for the future availability
     * engine — elapsed estimated durations are never used to free a table.
     */
    public function hasActiveDineInOrder(): bool
    {
        return $this->orders()
            ->where('order_type', Order::TYPE_DINE_IN)
            ->whereIn('status', Order::ACTIVE_STATUSES)
            ->exists();
    }
}
