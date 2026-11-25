<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['public_id', 'visitor_key_hash', 'visitor_name', 'visitor_email', 'status', 'assigned_to', 'assigned_at', 'last_message_at', 'closed_at'])]
#[Hidden(['visitor_key_hash'])]
class ChatConversation extends Model
{
    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'last_message_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function messages(): HasMany { return $this->hasMany(ChatMessage::class, 'conversation_id'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function auditLogs(): HasMany { return $this->hasMany(ChatAuditLog::class, 'conversation_id'); }
}
