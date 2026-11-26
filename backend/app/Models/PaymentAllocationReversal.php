<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payment_allocation_id', 'operation_reference', 'reversed_by', 'actor_user_id_snapshot', 'actor_name_snapshot', 'actor_reference_snapshot', 'reason', 'payment_unallocated_before', 'payment_unallocated_after', 'invoice_balance_before', 'invoice_balance_after', 'reversed_at'])]
class PaymentAllocationReversal extends Model
{
    protected function casts(): array
    {
        return ['payment_unallocated_before' => 'decimal:2', 'payment_unallocated_after' => 'decimal:2', 'invoice_balance_before' => 'decimal:2', 'invoice_balance_after' => 'decimal:2', 'reversed_at' => 'datetime'];
    }

    public function allocation(): BelongsTo { return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'reversed_by'); }
}
