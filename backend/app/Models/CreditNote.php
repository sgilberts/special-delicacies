<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'credit_note_number', 'status', 'customer_id', 'organisation_id', 'commercial_document_id',
    'original_document_number', 'original_document_date', 'issue_date', 'currency', 'reason', 'notes',
    'subtotal', 'discount_amount', 'discount_description', 'tax_amount', 'total',
    'created_by', 'created_by_name_snapshot', 'updated_by', 'submitted_by', 'submitted_at',
    'approved_by', 'approved_by_name_snapshot', 'approved_at', 'issued_by', 'issued_by_name_snapshot',
    'issued_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
])]
class CreditNote extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PARTIALLY_APPLIED = 'partially_applied';
    public const STATUS_FULLY_APPLIED = 'fully_applied';
    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses that reduce the original document's remaining creditable value. */
    public const EFFECTIVE_STATUSES = [
        self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_ISSUED,
        self::STATUS_PARTIALLY_APPLIED, self::STATUS_FULLY_APPLIED,
    ];

    /** Statuses that allow the credit note to be applied to its original document. */
    public const APPLICABLE_STATUSES = [self::STATUS_ISSUED, self::STATUS_PARTIALLY_APPLIED];

    protected function casts(): array
    {
        return [
            'original_document_date' => 'date',
            'issue_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }
    public function commercialDocument(): BelongsTo { return $this->belongsTo(CommercialDocument::class); }
    public function lines(): HasMany { return $this->hasMany(CreditNoteLine::class)->orderBy('position'); }
    public function applications(): HasMany { return $this->hasMany(CreditNoteApplication::class)->orderBy('applied_at')->orderBy('id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
}
