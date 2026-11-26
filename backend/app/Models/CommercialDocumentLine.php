<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'commercial_document_id', 'menu_item_id', 'description', 'detail', 'unit_of_measure', 'quantity', 'unit_price',
    'discount_amount', 'line_subtotal', 'tax_rate', 'tax_amount', 'line_total', 'position',
])]
class CommercialDocumentLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'line_subtotal' => 'decimal:2', 'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2',
        ];
    }

    public function document(): BelongsTo { return $this->belongsTo(CommercialDocument::class, 'commercial_document_id'); }
    public function menuItem(): BelongsTo { return $this->belongsTo(MenuItem::class); }
}
