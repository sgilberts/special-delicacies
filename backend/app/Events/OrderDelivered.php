<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

class OrderDelivered extends BaseOrderEvent
{
    /**
     * Broadcast to waiter and kitchen operational channels.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('orders.waiter'),
            new PrivateChannel('orders.kitchen'),
        ];

        if ($this->order->order_type === \App\Models\Order::TYPE_DELIVERY) {
            $channels[] = new PrivateChannel('orders.delivery');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.delivered';
    }
}
