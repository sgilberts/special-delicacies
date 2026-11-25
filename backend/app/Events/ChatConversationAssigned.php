<?php

namespace App\Events;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatConversationAssigned implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public ChatConversation $conversation, public int $recipientId) {}
    public function broadcastOn(): PrivateChannel { return new PrivateChannel('chat.staff.'.$this->recipientId); }
    public function broadcastAs(): string { return 'chat.conversation.assigned'; }
    public function broadcastWith(): array { return ['conversation_id' => $this->conversation->id, 'public_id' => $this->conversation->public_id]; }
}
