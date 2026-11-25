<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;

class OrderOutForDelivery extends BaseOrderEvent
{
    /**
     * Broadcast on the waiter channel, kitchen channel, delivery channel and per-order channel.
     *
     * @return array<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('orders.waiter'),
            new PrivateChannel('orders.kitchen'),
            new PrivateChannel('orders.delivery'),
            new PrivateChannel('orders.' . $this->order->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.out_for_delivery';
    }
}
