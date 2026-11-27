<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * A membership of an organisation was created or changed. Broadcast on the
 * organisation channel so open organisation modals refresh their member list
 * and counts without a page reload.
 */
class OrganisationMembershipChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $organisationId,
        public readonly string $action,
        public readonly ?int $membershipId = null,
        public readonly ?string $membershipStatus = null,
        public readonly ?string $role = null,
        public readonly ?int $membersCount = null,
        public readonly ?int $activeMembersCount = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('organisations')];
    }

    public function broadcastAs(): string
    {
        return 'organisation.membership.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'action' => $this->action,
            'organisation_id' => $this->organisationId,
            'membership' => $this->membershipId === null ? null : [
                'id' => $this->membershipId,
                'status' => $this->membershipStatus,
                'role' => $this->role,
            ],
            'members_count' => $this->membersCount,
            'active_members_count' => $this->activeMembersCount,
            'occurred_at' => now()->toISOString(),
        ];
    }
}
