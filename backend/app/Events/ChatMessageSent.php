<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public ChatMessage $message) { $this->message->loadMissing('conversation'); }

    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;
        $channels = [new Channel('chat.visitor.'.$conversation->visitor_key_hash)];
        if ($conversation->assigned_to) $channels[] = new PrivateChannel('chat.conversation.'.$conversation->id);
        if ($conversation->assigned_to) $channels[] = new PrivateChannel('chat.staff.'.$conversation->assigned_to);
        $viewAll = \App\Models\User::query()->where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('permissions.slug', 'chat.view_all'))
            ->pluck('id');
        foreach ($viewAll as $userId) {
            if ((int) $userId !== (int) $conversation->assigned_to) $channels[] = new PrivateChannel('chat.staff.'.$userId);
        }
        return $channels;
    }

    public function broadcastAs(): string { return 'chat.message.sent'; }

    public function broadcastWith(): array
    {
        return ['message' => [
            'id' => $this->message->id,
            'client_message_id' => $this->message->client_message_id,
            'author_kind' => $this->message->author_kind,
            'message_type' => $this->message->message_type,
            'body' => $this->message->deleted_at ? null : $this->message->body,
            'attachment_name' => $this->message->deleted_at ? null : $this->message->attachment_name,
            'created_at' => $this->message->created_at?->toISOString(),
        ]];
    }
}
