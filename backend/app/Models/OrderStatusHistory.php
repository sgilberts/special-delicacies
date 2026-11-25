<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'previous_status',
    'new_status',
    'changed_by',
    'note',
])]
class OrderStatusHistory extends Model
{
    use HasFactory;

    /**
     * Order this history record belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * User who initiated this status transition.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
