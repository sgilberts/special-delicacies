<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['venue_id', 'closure_type', 'starts_at', 'ends_at', 'reason', 'created_by'])]
class VenueClosure extends Model
{
    use HasFactory;

    public const TYPE_CLOSURE = 'closure';
    public const TYPE_MAINTENANCE = 'maintenance';

    public const TYPES = [self::TYPE_CLOSURE, self::TYPE_MAINTENANCE];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
