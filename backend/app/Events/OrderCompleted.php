<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

class OrderCompleted extends BaseOrderEvent
{
    /**
     * Broadcast to waiter and kitchen operational channels.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('orders.waiter'),
            new PrivateChannel('orders.kitchen'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.completed';
    }
}
