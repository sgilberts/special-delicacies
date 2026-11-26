<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'organisation_id', 'action', 'entity_type', 'entity_id', 'changed_fields', 'ip_address', 'user_agent'])]
class CustomerAuditLog extends Model
{
    protected function casts(): array
    {
        return ['changed_fields' => 'array'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
