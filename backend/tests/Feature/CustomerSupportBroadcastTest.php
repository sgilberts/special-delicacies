<?php

namespace Tests\Feature;

use App\Events\CustomerChanged;
use App\Events\OrganisationChanged;
use App\Events\OrganisationMembershipChanged;
use App\Models\Customer;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganisationManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerSupportBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Support Broadcast', 'slug' => 'support-broadcast']);
        $role->permissions()->sync(Permission::whereIn('slug', [
            'customers.view', 'customers.create', 'customers.update', 'customers.archive', 'customers.restore',
            'organisations.view', 'organisations.create', 'organisations.update', 'organisations.archive',
            'organisations.restore', 'organisations.manage_members',
        ])->pluck('id'));

        $this->staff = User::factory()->create(['is_active' => true]);
        $this->staff->roles()->attach($role);
        Sanctum::actingAs($this->staff);
    }

    public function test_customer_create_update_archive_and_restore_emit_events_after_commit(): void
    {
        $seen = [];
        Event::listen(CustomerChanged::class, function (CustomerChanged $event) use (&$seen): void {
            // The record must already be readable when the event fires: the
            // event is only published after the transaction committed.
            $seen[] = ['action' => $event->action, 'persisted' => Customer::query()->whereKey($event->customerId)->exists()];
        });

        $created = $this->postJson('/api/v1/admin/customers', [
            'name' => 'Broadcast Customer',
            'phone' => '0200000201',
            'email' => 'broadcast.customer@example.test',
        ])->assertCreated();

        $id = $created->json('data.id');
        $this->putJson("/api/v1/admin/customers/{$id}", ['name' => 'Broadcast Customer Renamed'])->assertOk();
        $this->postJson("/api/v1/admin/customers/{$id}/archive")->assertOk();
        $this->postJson("/api/v1/admin/customers/{$id}/restore")->assertOk();

        $this->assertSame(['created', 'updated', 'archived', 'restored'], array_column($seen, 'action'));
        foreach ($seen as $event) {
            $this->assertTrue($event['persisted'], 'Events must be published only after the transaction committed.');
        }
    }

    public function test_organisation_create_update_archive_and_restore_emit_events(): void
    {
        Event::fake([OrganisationChanged::class]);
        $owner = $this->customer('0200000202');

        $created = $this->postJson('/api/v1/admin/organisations', [
            'name' => 'Broadcast Organisation',
            'owner_customer_id' => $owner->id,
        ])->assertCreated();

        $id = $created->json('data.organisation.id');
        Event::assertDispatched(OrganisationChanged::class, fn (OrganisationChanged $event) => $event->organisationId === $id && $event->action === 'created');

        $this->putJson("/api/v1/admin/organisations/{$id}", ['name' => 'Broadcast Organisation Renamed'])->assertOk();
        Event::assertDispatched(OrganisationChanged::class, fn (OrganisationChanged $event) => $event->organisationId === $id && $event->action === 'updated');

        $this->postJson("/api/v1/admin/organisations/{$id}/archive")->assertOk();
        Event::assertDispatched(OrganisationChanged::class, fn (OrganisationChanged $event) => $event->organisationId === $id && $event->action === 'archived');

        $this->postJson("/api/v1/admin/organisations/{$id}/restore")->assertOk();
        Event::assertDispatched(OrganisationChanged::class, fn (OrganisationChanged $event) => $event->organisationId === $id && $event->action === 'restored');
    }

    public function test_membership_changes_emit_a_membership_event_with_counts(): void
    {
        Event::fake([OrganisationMembershipChanged::class]);
        $owner = $this->customer('0200000203');
        $organisation = $this->organisation($owner, 'Membership Broadcast Organisation');
        $member = $this->customer('0200000204');
        $membership = $organisation->memberships()->create([
            'customer_id' => $member->id,
            'role' => OrganisationMembership::ROLE_MEMBER,
            'status' => OrganisationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        $this->patchJson("/api/v1/admin/organisations/{$organisation->id}/members/{$membership->id}/status", ['status' => 'suspended'])
            ->assertOk();

        Event::assertDispatched(OrganisationMembershipChanged::class, function (OrganisationMembershipChanged $event) use ($organisation, $membership) {
            return $event->organisationId === $organisation->id
                && $event->membershipId === $membership->id
                && $event->membershipStatus === 'suspended'
                && $event->membersCount === 2
                && $event->activeMembersCount === 1;
        });
    }

    public function test_validation_failure_emits_no_event(): void
    {
        Event::fake([OrganisationChanged::class, CustomerChanged::class]);
        $owner = $this->customer('0200000205');
        $this->organisation($owner, 'Existing Duplicate Name');

        $this->postJson('/api/v1/admin/organisations', [
            'name' => 'Existing Duplicate Name',
            'owner_customer_id' => $owner->id,
        ])->assertStatus(422);

        Event::assertNotDispatched(OrganisationChanged::class);
        Event::assertNotDispatched(CustomerChanged::class);
    }

    public function test_a_failed_transaction_emits_no_event(): void
    {
        Event::fake([OrganisationChanged::class]);
        $owner = $this->customer('0200000206');

        // Force a failure after the organisation row is written but before the
        // service's transaction commits.
        Organisation::created(function (): void {
            throw new \RuntimeException('Forced failure inside the transaction.');
        });

        try {
            app(OrganisationManagementService::class)->create([
                'name' => 'Rollback Organisation',
                'owner_customer_id' => $owner->id,
            ], $this->staff, request());
            $this->fail('The transaction should have failed.');
        } catch (\RuntimeException) {
            // Expected: the transaction rolled back.
        }

        $this->assertDatabaseMissing('organisations', ['name' => 'Rollback Organisation']);
        Event::assertNotDispatched(OrganisationChanged::class);
    }

    public function test_private_channels_require_the_matching_view_permission(): void
    {
        $allowed = $this->staff;

        $this->assertTrue((bool) $this->resolveChannel('customers', $allowed));
        $this->assertTrue((bool) $this->resolveChannel('organisations', $allowed));

        $customerOnlyRole = Role::create(['name' => 'Customer Only', 'slug' => 'customer-only']);
        $customerOnlyRole->permissions()->sync(Permission::where('slug', 'customers.view')->pluck('id'));
        $customerOnly = User::factory()->create(['is_active' => true]);
        $customerOnly->roles()->attach($customerOnlyRole);

        $this->assertTrue((bool) $this->resolveChannel('customers', $customerOnly));
        $this->assertFalse((bool) $this->resolveChannel('organisations', $customerOnly), 'A customer viewer must not subscribe to organisation updates.');

        $stranger = User::factory()->create(['is_active' => true]);
        $this->assertFalse((bool) $this->resolveChannel('customers', $stranger));
        $this->assertFalse((bool) $this->resolveChannel('organisations', $stranger));

        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->roles()->attach($customerOnlyRole);
        $this->assertFalse((bool) $this->resolveChannel('customers', $inactive), 'An inactive account must not subscribe.');
    }

    public function test_event_payloads_carry_no_financial_information(): void
    {
        $customerPayload = (new CustomerChanged(7, 'updated', 'active'))->broadcastWith();
        $this->assertSame(['id', 'status'], array_keys($customerPayload['customer']));
        $this->assertSame('customer.changed', (new CustomerChanged(7, 'updated', 'active'))->broadcastAs());
        $this->assertSame('private-customers', (new CustomerChanged(7, 'updated', 'active'))->broadcastOn()[0]->name);

        $organisationPayload = (new OrganisationChanged(9, 'created', 'active', 3, 2))->broadcastWith();
        $this->assertSame(['id', 'status', 'members_count', 'active_members_count'], array_keys($organisationPayload['organisation']));
        $this->assertSame('organisation.changed', (new OrganisationChanged(9, 'created'))->broadcastAs());
        $this->assertSame('private-organisations', (new OrganisationChanged(9, 'created'))->broadcastOn()[0]->name);

        $membershipPayload = (new OrganisationMembershipChanged(9, 'status_changed', 4, 'suspended', 'member', 3, 2))->broadcastWith();
        $this->assertSame('organisation.membership.changed', (new OrganisationMembershipChanged(9, 'status_changed'))->broadcastAs());
        $this->assertArrayNotHasKey('balance', $membershipPayload);
        $this->assertArrayNotHasKey('payments', $membershipPayload);
    }

    private function resolveChannel(string $channelName, User $user): mixed
    {
        $manager = app(\Illuminate\Broadcasting\BroadcastManager::class);
        $broadcaster = $manager->connection();

        $reflection = new \ReflectionObject($broadcaster);
        $channelsProp = $reflection->getProperty('channels');
        $channelsProp->setAccessible(true);
        $channels = $channelsProp->getValue($broadcaster);

        foreach ($channels as $pattern => $callback) {
            if ($pattern === $channelName && is_callable($callback)) {
                return $callback($user);
            }
        }

        return false;
    }

    private function customer(string $phone): Customer
    {
        return Customer::create([
            'name' => 'Broadcast Customer '.$phone,
            'phone' => $phone,
            'email' => $phone.'@example.test',
            'password' => 'test-password',
            'is_active' => true,
        ]);
    }

    private function organisation(Customer $owner, string $name): Organisation
    {
        $organisation = Organisation::create([
            'reference' => 'ORG-'.strtoupper((string) str()->ulid()),
            'owner_customer_id' => $owner->id,
            'name' => $name,
            'status' => Organisation::STATUS_ACTIVE,
        ]);

        $organisation->memberships()->create([
            'customer_id' => $owner->id,
            'role' => OrganisationMembership::ROLE_OWNER,
            'status' => OrganisationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        return $organisation;
    }
}
