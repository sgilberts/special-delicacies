<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'refund_reference', 'status', 'refund_method', 'payment_transaction_id', 'payment_allocation_id', 'order_id',
    'commercial_document_id', 'customer_id', 'organisation_id', 'amount', 'currency', 'reason', 'notes',
    'operation_reference', 'initiated_by', 'initiated_by_snapshot', 'initiated_by_name_snapshot',
    'initiated_by_reference_snapshot', 'requested_at', 'reviewed_by', 'reviewed_at', 'approved_by',
    'approved_by_name_snapshot', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
    'processing_at', 'completed_at', 'failed_at', 'failure_reason', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
    'reversed_at', 'reversal_reason', 'reversed_by', 'gateway_reference', 'gateway_status',
    'external_transaction_reference', 'gateway_idempotency_key', 'credit_ledger_entry_id',
])]
class Refund extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVERSED = 'reversed';

    public const METHOD_ORIGINAL = 'original_payment_method';
    public const METHOD_CUSTOMER_CREDIT = 'customer_credit';
    public const METHOD_MANUAL = 'manual';

    /** Statuses that reserve part of a payment's refundable balance. */
    public const RESERVING_STATUSES = [
        self::STATUS_SUBMITTED, self::STATUS_PENDING_REVIEW, self::STATUS_APPROVED,
        self::STATUS_PROCESSING, self::STATUS_COMPLETED,
    ];

    /** Statuses that still count as money owed back to the customer. */
    public const OPEN_STATUSES = [
        self::STATUS_SUBMITTED, self::STATUS_PENDING_REVIEW, self::STATUS_APPROVED,
        self::STATUS_PROCESSING, self::STATUS_COMPLETED,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'processing_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo { return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id'); }
    public function allocation(): BelongsTo { return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function commercialDocument(): BelongsTo { return $this->belongsTo(CommercialDocument::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }
    public function initiator(): BelongsTo { return $this->belongsTo(User::class, 'initiated_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function creditLedgerEntry(): BelongsTo { return $this->belongsTo(CustomerCreditLedgerEntry::class, 'credit_ledger_entry_id'); }
}
