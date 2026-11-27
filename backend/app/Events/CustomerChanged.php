<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * A customer record changed in a committed transaction.
 *
 * The payload deliberately carries identity, lifecycle status and a stable
 * event id only — never financial figures. Authorised administrators refetch
 * the record (and its permission-gated financials) after receiving it.
 */
class CustomerChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $customerId,
        public readonly string $action,
        public readonly ?string $status = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('customers')];
    }

    public function broadcastAs(): string
    {
        return 'customer.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'action' => $this->action,
            'customer' => [
                'id' => $this->customerId,
                'status' => $this->status,
            ],
            'occurred_at' => now()->toISOString(),
        ];
    }
}
