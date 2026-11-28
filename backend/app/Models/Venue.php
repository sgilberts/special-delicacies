<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'code',
    'description',
    'venue_type',
    'location',
    'max_capacity',
    'operational_status',
    'reservation_enabled',
    'exclusive_reservation_enabled',
    'default_reservation_duration_minutes',
    'reservation_slot_minutes',
    'internal_notes',
])]
class Venue extends Model
{
    use HasFactory;

    public const TYPE_DINING_AREA = 'dining_area';
    public const TYPE_PRIVATE_ROOM = 'private_room';
    public const TYPE_TERRACE = 'terrace';
    public const TYPE_EVENT_SPACE = 'event_space';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_DINING_AREA,
        self::TYPE_PRIVATE_ROOM,
        self::TYPE_TERRACE,
        self::TYPE_EVENT_SPACE,
        self::TYPE_OTHER,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_MAINTENANCE,
    ];

    protected function casts(): array
    {
        return [
            'max_capacity' => 'integer',
            'reservation_enabled' => 'boolean',
            'exclusive_reservation_enabled' => 'boolean',
            'default_reservation_duration_minutes' => 'integer',
            'reservation_slot_minutes' => 'integer',
        ];
    }

    /** Physical tables in this venue (shared dine-in/reservation registry). */
    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class, 'venue_id');
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(VenueOperatingHour::class, 'venue_id')->orderBy('day_of_week');
    }

    public function specialHours(): HasMany
    {
        return $this->hasMany(VenueSpecialHour::class, 'venue_id')->orderBy('date');
    }

    public function closures(): HasMany
    {
        return $this->hasMany(VenueClosure::class, 'venue_id')->orderBy('starts_at');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'venue_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('operational_status', self::STATUS_ACTIVE);
    }

    public function scopeReservable(Builder $query): Builder
    {
        return $query->where('reservation_enabled', true)
            ->where('operational_status', self::STATUS_ACTIVE);
    }
}
