<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'commercial_document_id', 'user_id', 'customer_id', 'action', 'from_status', 'to_status', 'metadata', 'ip_address', 'user_agent',
])]
class CommercialDocumentHistory extends Model
{
    public $timestamps = false;

    protected function casts(): array { return ['metadata' => 'array', 'created_at' => 'datetime']; }
    public function document(): BelongsTo { return $this->belongsTo(CommercialDocument::class, 'commercial_document_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
