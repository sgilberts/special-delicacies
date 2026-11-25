<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPaymentStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders')];
    }

    public function broadcastAs(): string
    {
        return 'order.payment.updated';
    }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->order->id, 'payment_status' => $this->order->payment_status];
    }
}
