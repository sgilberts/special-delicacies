<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['conversation_id', 'client_message_id', 'author_kind', 'author_id', 'message_type', 'body', 'attachment_path', 'thumbnail_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'delivered_at', 'read_at', 'deleted_by'])]
class ChatMessage extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function conversation(): BelongsTo { return $this->belongsTo(ChatConversation::class, 'conversation_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
}
