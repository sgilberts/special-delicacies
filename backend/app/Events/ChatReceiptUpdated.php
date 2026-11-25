<?php

namespace App\Events;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatReceiptUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ChatConversation $conversation,
        public string $reader,
        public string $status,
        public array $messageIds,
        public array $staffRecipientIds = [],
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new Channel('chat.visitor.'.$this->conversation->visitor_key_hash)];
        if ($this->conversation->assigned_to) $channels[] = new PrivateChannel('chat.conversation.'.$this->conversation->id);
        foreach ($this->staffRecipientIds as $userId) $channels[] = new PrivateChannel('chat.staff.'.$userId);
        return $channels;
    }
    public function broadcastAs(): string { return 'chat.receipt.updated'; }
    public function broadcastWith(): array { return ['reader' => $this->reader, 'status' => $this->status, 'message_ids' => $this->messageIds]; }
}
