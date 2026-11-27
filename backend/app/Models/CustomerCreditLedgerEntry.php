<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'entry_type', 'operation_reference', 'source_payment_transaction_id', 'commercial_document_id', 'customer_id', 'organisation_id',
    'actor_user_id', 'actor_user_id_snapshot', 'actor_name_snapshot', 'actor_reference_snapshot', 'amount', 'currency', 'reason',
    'credit_balance_before', 'credit_balance_after', 'payment_unallocated_before', 'payment_unallocated_after',
    'invoice_outstanding_before', 'invoice_outstanding_after',
])]
class CustomerCreditLedgerEntry extends Model
{
    public const TYPE_DESIGNATION = 'designation';
    public const TYPE_APPLICATION = 'application';

    public $timestamps = false;

    /**
     * The customer credit ledger is append-only: historical entries must never
     * be rewritten or removed. Corrections belong in compensating entries, so
     * any attempt to mutate or delete an entry fails loudly.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \DomainException('Customer credit ledger entries are immutable.');
        });
        static::deleting(function (): void {
            throw new \DomainException('Customer credit ledger entries cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'credit_balance_before' => 'decimal:2', 'credit_balance_after' => 'decimal:2',
            'payment_unallocated_before' => 'decimal:2', 'payment_unallocated_after' => 'decimal:2',
            'invoice_outstanding_before' => 'decimal:2', 'invoice_outstanding_after' => 'decimal:2', 'created_at' => 'datetime',
        ];
    }

    public function sourcePayment(): BelongsTo { return $this->belongsTo(PaymentTransaction::class, 'source_payment_transaction_id'); }
    public function commercialDocument(): BelongsTo { return $this->belongsTo(CommercialDocument::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
