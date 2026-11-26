<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'document_number', 'document_type', 'status', 'customer_id', 'organisation_id', 'issue_date', 'expiry_date',
    'currency', 'subtotal', 'discount_amount', 'discount_description', 'tax_amount', 'total', 'notes', 'terms',
    'created_by', 'updated_by', 'issued_by', 'issued_at', 'accepted_at', 'declined_at', 'cancelled_at',
    'voided_at', 'void_reason',
])]
class CommercialDocument extends Model
{
    public const TYPE_QUOTATION = 'quotation';
    public const TYPE_INVOICE = 'invoice';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_VOIDED = 'voided';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
    public function lines(): HasMany { return $this->hasMany(CommercialDocumentLine::class)->orderBy('position'); }
    public function histories(): HasMany { return $this->hasMany(CommercialDocumentHistory::class)->orderBy('created_at')->orderBy('id'); }
}
