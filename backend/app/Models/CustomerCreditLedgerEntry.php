<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'entry_type', 'operation_reference', 'source_payment_transaction_id', 'commercial_document_id', 'reverses_entry_id',
    'customer_id', 'organisation_id', 'actor_user_id', 'actor_user_id_snapshot', 'actor_name_snapshot',
    'actor_reference_snapshot', 'authorized_by_user_id', 'authorized_by_name_snapshot', 'authorized_at',
    'amount', 'currency', 'reason', 'credit_balance_before', 'credit_balance_after',
    'payment_unallocated_before', 'payment_unallocated_after', 'invoice_outstanding_before', 'invoice_outstanding_after',
])]
class CustomerCreditLedgerEntry extends Model
{
    public const TYPE_DESIGNATION = 'designation';
    public const TYPE_APPLICATION = 'application';
    public const TYPE_CREDIT_NOTE_CREDIT = 'credit_note_credit';
    public const TYPE_REVERSAL = 'reversal';

    /** Entry types that increase the customer's available credit. */
    public const CREDIT_TYPES = [self::TYPE_DESIGNATION, self::TYPE_CREDIT_NOTE_CREDIT];

    /** Entry types that reduce the customer's available credit. */
    public const DEBIT_TYPES = [self::TYPE_APPLICATION, self::TYPE_REVERSAL];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'credit_balance_before' => 'decimal:2', 'credit_balance_after' => 'decimal:2',
            'payment_unallocated_before' => 'decimal:2', 'payment_unallocated_after' => 'decimal:2',
            'invoice_outstanding_before' => 'decimal:2', 'invoice_outstanding_after' => 'decimal:2',
            'created_at' => 'datetime', 'authorized_at' => 'datetime',
        ];
    }

    /**
     * The credit ledger is append-only. Corrections must be new compensating
     * entries so historical financial snapshots are never rewritten.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Customer credit ledger entries are append-only and cannot be updated.'));
        static::deleting(fn () => throw new DomainException('Customer credit ledger entries are append-only and cannot be deleted.'));
    }

    public function sourcePayment(): BelongsTo { return $this->belongsTo(PaymentTransaction::class, 'source_payment_transaction_id'); }
    public function commercialDocument(): BelongsTo { return $this->belongsTo(CommercialDocument::class); }
    public function reversesEntry(): BelongsTo { return $this->belongsTo(self::class, 'reverses_entry_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
    public function authorizedBy(): BelongsTo { return $this->belongsTo(User::class, 'authorized_by_user_id'); }
}
