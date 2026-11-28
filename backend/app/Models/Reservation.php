<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference',
    'customer_id',
    'organisation_id',
    'venue_id',
    'category',
    'reservation_date',
    'start_time',
    'end_time',
    'guest_count',
    'requested_table_count',
    'status',
    'commercial_document_id',
    'booking_value',
    'booking_currency',
    'deposit_required',
    'deposit_percentage',
    'deposit_amount',
    'deposit_currency',
    'deposit_policy_snapshot',
    'special_instructions',
    'internal_notes',
    'created_by',
])]
class Reservation extends Model
{
    use HasFactory;

    public const CATEGORY_SINGLE_TABLE = 'single_table';
    public const CATEGORY_MULTIPLE_TABLES = 'multiple_tables';
    public const CATEGORY_EXCLUSIVE_VENUE = 'exclusive_venue';

    public const CATEGORIES = [
        self::CATEGORY_SINGLE_TABLE,
        self::CATEGORY_MULTIPLE_TABLES,
        self::CATEGORY_EXCLUSIVE_VENUE,
    ];

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_CONFIRMED,
        self::STATUS_CANCELLED,
        self::STATUS_COMPLETED,
        self::STATUS_NO_SHOW,
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            'guest_count' => 'integer',
            'requested_table_count' => 'integer',
            'booking_value' => 'decimal:2',
            'deposit_required' => 'boolean',
            'deposit_percentage' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'deposit_policy_snapshot' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function commercialDocument(): BelongsTo
    {
        return $this->belongsTo(CommercialDocument::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ReservationTableAllocation::class, 'reservation_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class, 'reservation_id')->orderBy('created_at');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
