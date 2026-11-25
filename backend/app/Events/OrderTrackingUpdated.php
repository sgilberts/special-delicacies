<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Public, capability-addressed progress update. This event never carries customer data. */
class OrderTrackingUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}

    public static function channelName(string $orderNumber): string
    {
        return 'tracking.'.hash('sha256', $orderNumber);
    }

    public function broadcastOn(): Channel
    {
        return new Channel(self::channelName($this->order->order_number));
    }

    public function broadcastAs(): string
    {
        return 'order.tracking.updated';
    }

    public function broadcastWith(): array
    {
        return ['status' => $this->order->status];
    }
}
