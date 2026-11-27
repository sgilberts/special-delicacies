<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'entry_type', 'operation_reference', 'source_payment_transaction_id', 'source_refund_id', 'commercial_document_id', 'customer_id', 'organisation_id',
    'actor_user_id', 'actor_user_id_snapshot', 'actor_name_snapshot', 'actor_reference_snapshot', 'amount', 'currency', 'reason',
    'credit_balance_before', 'credit_balance_after', 'payment_unallocated_before', 'payment_unallocated_after',
    'invoice_outstanding_before', 'invoice_outstanding_after',
])]
class CustomerCreditLedgerEntry extends Model
{
    public const TYPE_DESIGNATION = 'designation';
    public const TYPE_APPLICATION = 'application';
    /** Stage 4C: credit posted as the destination of a refund. */
    public const TYPE_REFUND_CREDIT = 'refund_credit';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'credit_balance_before' => 'decimal:2', 'credit_balance_after' => 'decimal:2',
            'payment_unallocated_before' => 'decimal:2', 'payment_unallocated_after' => 'decimal:2',
            'invoice_outstanding_before' => 'decimal:2', 'invoice_outstanding_after' => 'decimal:2', 'created_at' => 'datetime',
        ];
    }

    public function sourcePayment(): BelongsTo { return $this->belongsTo(PaymentTransaction::class, 'source_payment_transaction_id'); }
    public function sourceRefund(): BelongsTo { return $this->belongsTo(Refund::class, 'source_refund_id'); }
    public function commercialDocument(): BelongsTo { return $this->belongsTo(CommercialDocument::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
