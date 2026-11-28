<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * An organisation record changed in a committed transaction.
 *
 * Membership counts are included because they are profile-level facts every
 * `organisations.view` holder may see; financial totals never are.
 */
class OrganisationChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $organisationId,
        public readonly string $action,
        public readonly ?string $status = null,
        public readonly ?int $membersCount = null,
        public readonly ?int $activeMembersCount = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('organisations')];
    }

    public function broadcastAs(): string
    {
        return 'organisation.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'action' => $this->action,
            'organisation' => [
                'id' => $this->organisationId,
                'status' => $this->status,
                'members_count' => $this->membersCount,
                'active_members_count' => $this->activeMembersCount,
            ],
            'occurred_at' => now()->toISOString(),
        ];
    }
}
