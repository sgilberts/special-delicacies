<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reservation_id', 'restaurant_table_id', 'occupancy_start', 'occupancy_end', 'released_at'])]
class ReservationTableAllocation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'occupancy_start' => 'datetime',
            'occupancy_end' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }
}
