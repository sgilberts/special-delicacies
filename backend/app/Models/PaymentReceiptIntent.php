<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payment_transaction_id', 'intent', 'operation_reference', 'reason', 'actor_user_id', 'actor_user_id_snapshot', 'actor_name_snapshot', 'actor_reference_snapshot', 'designated_at'])]
class PaymentReceiptIntent extends Model
{
    protected function casts(): array { return ['designated_at' => 'datetime']; }
    public function paymentTransaction(): BelongsTo { return $this->belongsTo(PaymentTransaction::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
