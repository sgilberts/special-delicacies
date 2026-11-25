<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'actor_id', 'action', 'metadata'])]
class ChatAuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array { return ['metadata' => 'array', 'created_at' => 'datetime']; }
    public function conversation(): BelongsTo { return $this->belongsTo(ChatConversation::class, 'conversation_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
