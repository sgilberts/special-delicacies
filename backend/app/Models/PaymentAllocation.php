<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['payment_allocation_operation_id', 'commercial_document_id', 'amount', 'currency', 'invoice_balance_before', 'invoice_balance_after', 'allocated_at'])]
class PaymentAllocation extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'invoice_balance_before' => 'decimal:2', 'invoice_balance_after' => 'decimal:2', 'allocated_at' => 'datetime'];
    }

    public function operation(): BelongsTo { return $this->belongsTo(PaymentAllocationOperation::class, 'payment_allocation_operation_id'); }
    public function commercialDocument(): BelongsTo { return $this->belongsTo(CommercialDocument::class); }
    public function reversal(): HasOne { return $this->hasOne(PaymentAllocationReversal::class); }
}
