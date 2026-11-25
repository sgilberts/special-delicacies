<?php

namespace App\Events;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatTyping implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public ChatConversation $conversation, public string $sender) {}
    public function broadcastOn(): array { return [new Channel('chat.visitor.'.$this->conversation->visitor_key_hash), new PrivateChannel('chat.conversation.'.$this->conversation->id)]; }
    public function broadcastAs(): string { return 'chat.typing'; }
    public function broadcastWith(): array { return ['sender' => $this->sender]; }
}
