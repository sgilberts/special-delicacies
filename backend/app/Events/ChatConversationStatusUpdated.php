<?php

namespace App\Events;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatConversationStatusUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public ChatConversation $conversation, public string $status) {}

    public function broadcastOn(): array
    {
        $channels = [new Channel('chat.visitor.'.$this->conversation->visitor_key_hash)];
        if ($this->conversation->assigned_to) {
            $channels[] = new PrivateChannel('chat.conversation.'.$this->conversation->id);
            $channels[] = new PrivateChannel('chat.staff.'.$this->conversation->assigned_to);
        }
        $admins = User::query()->where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('permissions.slug', 'chat.view_all'))
            ->pluck('id');
        foreach ($admins as $userId) {
            if ((int) $userId !== (int) $this->conversation->assigned_to) {
                $channels[] = new PrivateChannel('chat.staff.'.$userId);
            }
        }
        return $channels;
    }

    public function broadcastAs(): string { return 'chat.conversation.status.updated'; }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversation->id, 'status' => $this->status];
    }
}
