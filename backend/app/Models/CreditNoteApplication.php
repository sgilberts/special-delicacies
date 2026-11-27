<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'credit_note_id', 'commercial_document_id', 'operation_reference', 'amount', 'currency', 'applied_by',
    'applied_by_snapshot', 'applied_by_name_snapshot', 'applied_by_reference_snapshot',
    'invoice_outstanding_before', 'invoice_outstanding_after', 'credit_note_applied_before',
    'credit_note_applied_after', 'applied_at',
])]
class CreditNoteApplication extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'invoice_outstanding_before' => 'decimal:2',
            'invoice_outstanding_after' => 'decimal:2',
            'credit_note_applied_before' => 'decimal:2',
            'credit_note_applied_after' => 'decimal:2',
            'applied_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function creditNote(): BelongsTo { return $this->belongsTo(CreditNote::class); }
    public function commercialDocument(): BelongsTo { return $this->belongsTo(CommercialDocument::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'applied_by'); }
}
