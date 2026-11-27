<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'refund_reference', 'payment_transaction_id', 'customer_id', 'organisation_id', 'amount', 'currency', 'status', 'reason',
    'requested_by', 'requested_by_id_snapshot', 'requested_by_name_snapshot', 'requested_by_reference_snapshot', 'requested_at',
    'approved_by', 'approved_at', 'approval_reason', 'rejected_by', 'rejected_at', 'rejection_reason',
    'provider', 'provider_refund_reference', 'submitted_at', 'confirmed_by', 'confirmed_at', 'verification_reference',
    'verification_evidence', 'failed_at', 'failure_reason', 'metadata',
])]
class RefundRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    /**
     * The provider instruction was dispatched (or is about to be) but its
     * outcome has not been persisted yet. A refund in this state must be
     * reconciled — never resubmitted automatically and never marked
     * confirmed, failed or rejected.
     */
    public const STATUS_SUBMITTING = 'submitting';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED = 'failed';

    public const TERMINAL = [self::STATUS_CONFIRMED, self::STATUS_REJECTED, self::STATUS_FAILED];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function payment(): BelongsTo { return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejecter(): BelongsTo { return $this->belongsTo(User::class, 'rejected_by'); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by'); }
    public function events(): HasMany { return $this->hasMany(RefundEvent::class)->orderBy('created_at')->orderBy('id'); }
}
