<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'refund_request_id', 'action', 'from_status', 'to_status', 'actor_user_id', 'actor_user_id_snapshot',
    'actor_name_snapshot', 'actor_reference_snapshot', 'reason', 'provider_reference', 'metadata',
])]
class RefundEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function refund(): BelongsTo { return $this->belongsTo(RefundRequest::class, 'refund_request_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
