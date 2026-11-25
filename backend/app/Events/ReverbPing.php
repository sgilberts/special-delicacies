<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReverbPing implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $data = []
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('reverb.monitor'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'reverb.ping';
    }

    public function broadcastWith(): array
    {
        return array_merge([
            'timestamp' => now()->toIso8601String(),
            'message' => 'Operational heartbeat test successful',
            'server' => 'Laravel Reverb',
            'host' => config('reverb.servers.reverb.host', '127.0.0.1'),
            'port' => config('reverb.servers.reverb.port', 8080),
        ], $this->data);
    }
}
