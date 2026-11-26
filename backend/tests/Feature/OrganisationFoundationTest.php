<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\Organisation;
use App\Models\OrganisationInvitation;
use App\Models\OrganisationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganisationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_an_organisation_with_transactional_owner_membership(): void
    {
        $customer = $this->customer('0201000001');
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/customer/organisations', [
            'name' => 'Amina Catering',
            'description' => 'Workplace catering',
            'business_email' => 'CATERING@example.com',
            'business_phone' => '+233 20 111 1111',
            'address' => 'Accra',
            'owner_customer_id' => 9999,
            'role' => 'member',
            'status' => 'suspended',
        ]);

        $response->assertCreated()->assertJsonPath('data.role', 'owner')->assertJsonPath('data.status', 'active');
        $organisation = Organisation::firstOrFail();
        $this->assertSame($customer->id, $organisation->owner_customer_id);
        $this->assertStringStartsWith('ORG-', $organisation->reference);
        $this->assertDatabaseHas('organisation_memberships', [
            'organisation_id' => $organisation->id,
            'customer_id' => $customer->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('customer_audit_logs', [
            'customer_id' => $customer->id,
            'organisation_id' => $organisation->id,
            'action' => 'organisation.created',
        ]);
    }

    public function test_customer_can_belong_to_multiple_organisations_and_one_org_can_have_multiple_customers(): void
    {
        $owner = $this->customer('0201000002');
        $member = $this->customer('0201000003');
        Sanctum::actingAs($owner);

        $first = $this->postJson('/api/v1/customer/organisations', ['name' => 'First Business'])->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/customer/organisations', ['name' => 'Second Business'])->assertCreated()->json('data.id');
        Organisation::findOrFail($first)->memberships()->create([
            'customer_id' => $member->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
            'approved_at' => now(),
        ]);

        $this->getJson('/api/v1/customer/organisations')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/customer/organisations/{$first}/members")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/customer/organisations/{$second}/members")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_owner_can_view_update_suspend_and_reactivate_organisation(): void
    {
        $owner = $this->customer('0201000004');
        $organisation = $this->createOrganisation($owner, 'Owner Business');
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/customer/organisations/{$organisation->id}")->assertOk()->assertJsonPath('data.role', 'owner');
        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}", ['name' => 'Updated Business'])
            ->assertOk()->assertJsonPath('data.name', 'Updated Business');
        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}/status", ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}", ['name' => 'Blocked Update'])->assertForbidden();
        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}/status", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame(4, CustomerAuditLog::where('organisation_id', $organisation->id)->count());
    }

    public function test_manager_can_view_but_cannot_manage_organisation_profile_or_members(): void
    {
        $owner = $this->customer('0201000005');
        $manager = $this->customer('0201000006');
        $organisation = $this->createOrganisation($owner);
        $member = $organisation->memberships()->create(['customer_id' => $manager->id, 'role' => 'manager', 'status' => 'active', 'joined_at' => now()]);
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/customer/organisations/{$organisation->id}")->assertOk();
        $this->getJson("/api/v1/customer/organisations/{$organisation->id}/members")->assertOk();
        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}", ['name' => 'Nope'])->assertForbidden();
        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}/members/{$member->id}/role", ['role' => 'administrator'])->assertForbidden();
        $this->assertSame('manager', $member->fresh()->role);
    }

    public function test_administrator_cannot_manage_owner_only_operations_or_elevate_roles(): void
    {
        $owner = $this->customer('0201000007');
        $administrator = $this->customer('0201000008');
        $member = $this->customer('0201000009');
        $organisation = $this->createOrganisation($owner);
        $organisation->memberships()->create(['customer_id' => $administrator->id, 'role' => 'administrator', 'status' => 'active', 'joined_at' => now()]);
        $memberRow = $organisation->memberships()->create(['customer_id' => $member->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
        Sanctum::actingAs($administrator);

        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}", ['name' => 'Admin Edit'])->assertOk();
        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}/status", ['status' => 'suspended'])->assertForbidden();
        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}/members/{$memberRow->id}/role", ['role' => 'administrator'])->assertForbidden();
        $this->assertSame('member', $memberRow->fresh()->role);
    }

    public function test_customer_cannot_access_or_change_another_organisations_records(): void
    {
        $customerA = $this->customer('0201000010');
        $customerB = $this->customer('0201000011');
        $organisationA = $this->createOrganisation($customerA, 'Organisation A');
        $organisationB = $this->createOrganisation($customerB, 'Organisation B');
        $memberB = $organisationB->memberships()->create(['customer_id' => $this->customer('0201000012')->id, 'role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($customerA);

        $this->getJson('/api/v1/customer/organisations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/customer/organisations/{$organisationB->id}")->assertNotFound();
        $this->getJson("/api/v1/customer/organisations/{$organisationB->id}/members")->assertNotFound();
        $this->patchJson("/api/v1/customer/organisations/{$organisationB->id}", ['name' => 'Compromised'])->assertNotFound();
        $this->patchJson("/api/v1/customer/organisations/{$organisationB->id}/members/{$memberB->id}/role", ['role' => 'administrator'])->assertNotFound();
        $this->patchJson("/api/v1/customer/organisations/{$organisationB->id}/members/{$memberB->id}/status", ['status' => 'removed'])->assertNotFound();

        $this->assertSame('Organisation B', $organisationB->fresh()->name);
        $this->assertSame('member', $memberB->fresh()->role);
        $this->assertSame(1, CustomerAuditLog::where('organisation_id', $organisationB->id)->count());
    }

    public function test_suspended_or_removed_memberships_cannot_access_an_organisation(): void
    {
        $owner = $this->customer('0201000013');
        $suspended = $this->customer('0201000014');
        $organisation = $this->createOrganisation($owner);
        $organisation->memberships()->create(['customer_id' => $suspended->id, 'role' => 'member', 'status' => 'suspended']);
        Sanctum::actingAs($suspended);
        $this->getJson("/api/v1/customer/organisations/{$organisation->id}")->assertNotFound();
    }

    public function test_pending_membership_cannot_access_an_organisation(): void
    {
        $owner = $this->customer('0201000015');
        $pending = $this->customer('0201000016');
        $organisation = $this->createOrganisation($owner);
        $organisation->memberships()->create(['customer_id' => $pending->id, 'role' => 'member', 'status' => 'pending']);
        Sanctum::actingAs($pending);
        $this->getJson("/api/v1/customer/organisations/{$organisation->id}")->assertNotFound();
    }

    public function test_owner_membership_cannot_be_role_changed_suspended_or_removed(): void
    {
        $owner = $this->customer('0201000017');
        $organisation = $this->createOrganisation($owner);
        $ownerMembership = $organisation->memberships()->where('customer_id', $owner->id)->firstOrFail();
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}/members/{$ownerMembership->id}/role", ['role' => 'manager'])->assertForbidden();
        $this->patchJson("/api/v1/customer/organisations/{$organisation->id}/members/{$ownerMembership->id}/status", ['status' => 'suspended'])->assertForbidden();
        $this->assertSame('owner', $ownerMembership->fresh()->role);
        $this->assertSame('active', $ownerMembership->fresh()->status);
    }

    public function test_duplicate_membership_pair_is_rejected_by_database_constraint(): void
    {
        $customer = $this->customer('0201000018');
        $organisation = $this->createOrganisation($customer);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('organisation_memberships')->insert([
            'organisation_id' => $organisation->id,
            'customer_id' => $customer->id,
            'role' => 'member',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_owner_can_invite_existing_customer_and_cancel_or_reissue_invitation(): void
    {
        $owner = $this->customer('0201000019');
        $target = $this->customer('0201000020');
        $organisation = $this->createOrganisation($owner);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/customer/organisations/{$organisation->id}/invitations", ['customer_id' => $target->id]);
        $response->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.token');
        $invitationId = $response->json('data.id');
        $this->assertDatabaseHas('organisation_invitations', ['id' => $invitationId, 'customer_id' => $target->id, 'status' => 'pending']);
        $this->assertDatabaseHas('customer_audit_logs', ['organisation_id' => $organisation->id, 'action' => 'invitation.created']);
        $this->postJson("/api/v1/customer/organisations/{$organisation->id}/invitations", ['customer_id' => $target->id])->assertStatus(409);

        $this->deleteJson("/api/v1/customer/organisations/{$organisation->id}/invitations/{$invitationId}")->assertOk();
        $this->assertSame('cancelled', OrganisationInvitation::findOrFail($invitationId)->status);
        $reissued = $this->postJson("/api/v1/customer/organisations/{$organisation->id}/invitations", ['customer_id' => $target->id]);
        $reissued->assertCreated()->assertJsonPath('data.id', $invitationId);
    }

    public function test_invitation_acceptance_activates_only_its_target_customer_once(): void
    {
        $owner = $this->customer('0201000021');
        $target = $this->customer('0201000022');
        $organisation = $this->createOrganisation($owner);
        $invitation = $organisation->invitations()->create([
            'customer_id' => $target->id,
            'invited_by' => $owner->id,
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);
        Sanctum::actingAs($target);

        $this->getJson('/api/v1/customer/invitations')->assertOk()->assertJsonPath('data.0.id', $invitation->id);
        $this->postJson("/api/v1/customer/invitations/{$invitation->id}/accept")->assertOk();
        $this->postJson("/api/v1/customer/invitations/{$invitation->id}/accept")->assertStatus(409);
        $this->assertDatabaseHas('organisation_memberships', [
            'organisation_id' => $organisation->id,
            'customer_id' => $target->id,
            'role' => 'member',
            'status' => 'active',
        ]);
        $this->assertSame('accepted', $invitation->fresh()->status);
    }

    public function test_invitation_acceptance_rejects_wrong_customer_and_expired_invitation(): void
    {
        $owner = $this->customer('0201000023');
        $target = $this->customer('0201000024');
        $organisation = $this->createOrganisation($owner);
        $invitation = $organisation->invitations()->create([
            'customer_id' => $target->id,
            'invited_by' => $owner->id,
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $wrongCustomer = $this->customer('0201000025');
        Sanctum::actingAs($wrongCustomer);
        $this->postJson("/api/v1/customer/invitations/{$invitation->id}/accept")->assertNotFound();
    }

    public function test_expired_invitation_is_marked_expired_when_target_attempts_acceptance(): void
    {
        $owner = $this->customer('0201000026');
        $target = $this->customer('0201000027');
        $organisation = $this->createOrganisation($owner);
        $invitation = $organisation->invitations()->create([
            'customer_id' => $target->id,
            'invited_by' => $owner->id,
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);
        Sanctum::actingAs($target);

        $this->postJson("/api/v1/customer/invitations/{$invitation->id}/accept")->assertStatus(410);
        $this->assertSame('expired', $invitation->fresh()->status);
        $this->assertDatabaseMissing('organisation_memberships', ['organisation_id' => $organisation->id, 'customer_id' => $target->id]);
    }

    private function customer(string $phone): Customer
    {
        static $number = 0;
        $number++;
        return Customer::create([
            'name' => 'Customer '.$number,
            'phone' => $phone,
            'email' => "customer{$number}@example.test",
            'password' => 'correct-horse-2026',
            'is_active' => true,
        ]);
    }

    private function createOrganisation(Customer $owner, string $name = 'Example Organisation'): Organisation
    {
        $organisation = Organisation::create([
            'reference' => 'ORG-'.strtoupper(bin2hex(random_bytes(8))),
            'owner_customer_id' => $owner->id,
            'name' => $name,
            'status' => Organisation::STATUS_ACTIVE,
        ]);
        $organisation->memberships()->create([
            'customer_id' => $owner->id,
            'role' => OrganisationMembership::ROLE_OWNER,
            'status' => OrganisationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
            'approved_at' => now(),
        ]);
        CustomerAuditLog::create([
            'customer_id' => $owner->id,
            'organisation_id' => $organisation->id,
            'action' => 'organisation.created',
            'entity_type' => Organisation::class,
            'entity_id' => (string) $organisation->id,
            'changed_fields' => ['fields' => ['name']],
        ]);
        return $organisation;
    }
}
