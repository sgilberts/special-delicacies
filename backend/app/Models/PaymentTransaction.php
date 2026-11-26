<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'order_id', 'customer_id', 'organisation_id', 'payment_purpose', 'transaction_reference', 'provider', 'payment_method', 'amount', 'currency',
    'status', 'provider_reference', 'verified_at', 'received_at', 'confirmed_by', 'metadata', 'reporting_date',
])]
class PaymentTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'verified_at' => 'datetime',
            'received_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function allocationOperations(): HasMany
    {
        return $this->hasMany(PaymentAllocationOperation::class);
    }

    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(PaymentAllocation::class, PaymentAllocationOperation::class, 'payment_transaction_id', 'payment_allocation_operation_id');
    }

    public function creditDesignations(): HasMany
    {
        return $this->hasMany(CustomerCreditLedgerEntry::class, 'source_payment_transaction_id')
            ->where('entry_type', CustomerCreditLedgerEntry::TYPE_DESIGNATION);
    }

    public function receiptIntent(): HasOne
    {
        return $this->hasOne(PaymentReceiptIntent::class);
    }
}
