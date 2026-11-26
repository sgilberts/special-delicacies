<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['payment_transaction_id', 'operation_reference', 'allocated_by', 'actor_user_id_snapshot', 'actor_name_snapshot', 'actor_reference_snapshot', 'payment_unallocated_before', 'payment_unallocated_after', 'reason', 'allocated_at'])]
class PaymentAllocationOperation extends Model
{
    protected function casts(): array
    {
        return ['payment_unallocated_before' => 'decimal:2', 'payment_unallocated_after' => 'decimal:2', 'allocated_at' => 'datetime'];
    }

    public function paymentTransaction(): BelongsTo { return $this->belongsTo(PaymentTransaction::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'allocated_by'); }
    public function allocations(): HasMany { return $this->hasMany(PaymentAllocation::class); }
}
