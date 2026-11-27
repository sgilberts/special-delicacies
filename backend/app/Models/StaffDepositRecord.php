<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_transaction_id', 'intent', 'operation_reference', 'external_reference', 'payment_method', 'evidence',
    'status', 'recorded_by', 'recorded_by_snapshot', 'recorded_by_name_snapshot', 'recorded_at',
    'verified_by', 'verified_by_snapshot', 'verified_by_name_snapshot', 'verified_at', 'decision_reason',
])]
class StaffDepositRecord extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    /**
     * A verified or rejected deposit record is a historical financial fact:
     * only the pending -> verified/rejected transition may be written, and the
     * record may never be deleted.
     */
    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->getOriginal('status') !== self::STATUS_PENDING) {
                throw new \DomainException('Verified deposit records are immutable.');
            }
        });
        static::deleting(function (): void {
            throw new \DomainException('Deposit records cannot be deleted.');
        });
    }

    public function paymentTransaction(): BelongsTo { return $this->belongsTo(PaymentTransaction::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
}
