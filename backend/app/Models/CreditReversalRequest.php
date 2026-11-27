<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'operation_reference', 'ledger_entry_id', 'customer_id', 'organisation_id', 'amount', 'currency', 'reason', 'status',
    'requested_by', 'requested_by_id_snapshot', 'requested_by_name_snapshot', 'requested_by_reference_snapshot', 'requested_at',
    'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason', 'reversal_ledger_entry_id',
])]
class CreditReversalRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function ledgerEntry(): BelongsTo { return $this->belongsTo(CustomerCreditLedgerEntry::class, 'ledger_entry_id'); }
    public function reversalEntry(): BelongsTo { return $this->belongsTo(CustomerCreditLedgerEntry::class, 'reversal_ledger_entry_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejecter(): BelongsTo { return $this->belongsTo(User::class, 'rejected_by'); }
}
