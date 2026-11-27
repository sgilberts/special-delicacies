<?php

namespace Tests\Feature;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganisationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Organisation Support', 'slug' => 'organisation-support']);
        $role->permissions()->sync(Permission::whereIn('slug', [
            'organisations.view', 'organisations.create', 'organisations.update', 'organisations.archive',
            'organisations.restore', 'organisations.manage_members', 'organisations.view_financials',
        ])->pluck('id'));

        $this->staff = User::factory()->create(['is_active' => true]);
        $this->staff->roles()->attach($role);
        Sanctum::actingAs($this->staff);
    }

    public function test_listing_searches_filters_sorts_and_paginates_on_the_server(): void
    {
        $owner = $this->customer('0200000101');
        $alpha = $this->organisation($owner, 'Alpha Catering', Organisation::STATUS_ACTIVE);
        $this->organisation($owner, 'Beta Foods', Organisation::STATUS_SUSPENDED);
        $archived = $this->organisation($owner, 'Gamma Events', Organisation::STATUS_ACTIVE);
        $archived->forceFill(['archived_at' => now()])->save();

        for ($index = 0; $index < 20; $index++) {
            $this->organisation($owner, 'Bulk Organisation '.$index, Organisation::STATUS_ACTIVE);
        }

        $search = $this->getJson('/api/v1/admin/organisations?search=Alpha');
        $search->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($alpha->id, $search->json('data.0.id'));
        $this->assertSame($alpha->reference, $search->json('data.0.reference'));
        $this->assertSame(1, $search->json('data.0.active_members_count'));

        $this->getJson('/api/v1/admin/organisations?status=archived')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/organisations?status=inactive')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/organisations?membership_status=pending')->assertOk()->assertJsonCount(0, 'data');

        $page = $this->getJson('/api/v1/admin/organisations?per_page=5&page=2');
        $page->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame(23, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.current_page'));
        $this->assertSame(5, $page->json('meta.last_page'));

        $sorted = $this->getJson('/api/v1/admin/organisations?sort=name&direction=asc&per_page=1');
        $sorted->assertOk();
        $this->assertSame('Alpha Catering', $sorted->json('data.0.name'));

        $this->getJson('/api/v1/admin/organisations')->assertOk()->assertJsonPath('counts.total', 23);
    }

    public function test_create_enforces_validation_duplicate_prevention_and_owner_rules(): void
    {
        $owner = $this->customer('0200000102');

        $created = $this->postJson('/api/v1/admin/organisations', [
            'name' => '  Adom Catering Ltd  ',
            'owner_customer_id' => $owner->id,
            'business_email' => 'ACCOUNTS@EXAMPLE.TEST',
            'business_phone' => '020 000 0102',
            'address' => '12 Market Road',
        ]);

        $created->assertCreated();
        $this->assertSame('Adom Catering Ltd', $created->json('data.organisation.name'));
        $this->assertSame('accounts@example.test', $created->json('data.organisation.business_email'));
        $this->assertSame('+233200000102', $created->json('data.organisation.business_phone'));
        $this->assertStringStartsWith('ORG-', $created->json('data.organisation.reference'));
        $this->assertSame(1, $created->json('data.organisation.membership.active'));

        $organisation = Organisation::query()->where('name', 'Adom Catering Ltd')->firstOrFail();
        $this->assertDatabaseHas('organisation_memberships', [
            'organisation_id' => $organisation->id,
            'customer_id' => $owner->id,
            'role' => OrganisationMembership::ROLE_OWNER,
            'status' => OrganisationMembership::STATUS_ACTIVE,
        ]);

        $this->postJson('/api/v1/admin/organisations', [
            'name' => 'adom catering ltd',
            'owner_customer_id' => $owner->id,
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/admin/organisations', [
            'name' => 'Another Organisation',
            'owner_customer_id' => $owner->id,
            'business_phone' => '12345',
        ])->assertStatus(422)->assertJsonValidationErrors('business_phone');

        $inactive = $this->customer('0200000103');
        $inactive->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/admin/organisations', [
            'name' => 'Blocked Owner Organisation',
            'owner_customer_id' => $inactive->id,
        ])->assertStatus(422)->assertJsonValidationErrors('owner_customer_id');
    }

    public function test_update_preserves_identity_and_records_an_audit_entry(): void
    {
        $owner = $this->customer('0200000104');
        $organisation = $this->organisation($owner, 'Original Name', Organisation::STATUS_ACTIVE);
        $reference = $organisation->reference;

        $response = $this->putJson("/api/v1/admin/organisations/{$organisation->id}", [
            'name' => 'Renamed Organisation',
            'business_email' => 'hello@example.test',
        ]);

        $response->assertOk();
        $this->assertSame('Renamed Organisation', $response->json('data.organisation.name'));
        $this->assertSame($reference, $organisation->fresh()->reference);
        $this->assertSame(1, $organisation->memberships()->count());
        $this->assertDatabaseHas('admin_audit_logs', [
            'entity_type' => Organisation::class,
            'entity_id' => (string) $organisation->id,
            'action' => 'organisation.updated',
        ]);
    }

    public function test_archive_and_restore_are_safe_lifecycle_operations(): void
    {
        $owner = $this->customer('0200000105');
        $organisation = $this->organisation($owner, 'Lifecycle Organisation', Organisation::STATUS_ACTIVE);
        $member = $this->customer('0200000106');
        $organisation->memberships()->create([
            'customer_id' => $member->id,
            'role' => OrganisationMembership::ROLE_MEMBER,
            'status' => OrganisationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        $document = $this->invoice($organisation);

        $this->postJson("/api/v1/admin/organisations/{$organisation->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.organisation.status', 'archived');

        $this->assertSame(2, $organisation->memberships()->count());
        $this->assertDatabaseHas('commercial_documents', ['id' => $document->id, 'organisation_id' => $organisation->id]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'entity_type' => Organisation::class,
            'entity_id' => (string) $organisation->id,
            'action' => 'organisation.archived',
        ]);

        $this->postJson("/api/v1/admin/organisations/{$organisation->id}/archive")->assertStatus(409);

        $this->postJson("/api/v1/admin/organisations/{$organisation->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.organisation.status', 'active');

        $this->postJson("/api/v1/admin/organisations/{$organisation->id}/restore")->assertStatus(409);
        $this->assertSame(2, $organisation->memberships()->count());
    }

    public function test_every_endpoint_enforces_its_own_permission(): void
    {
        $owner = $this->customer('0200000107');
        $organisation = $this->organisation($owner, 'Permission Organisation', Organisation::STATUS_ACTIVE);

        $viewerRole = Role::create(['name' => 'Organisation Viewer', 'slug' => 'organisation-viewer']);
        $viewerRole->permissions()->sync(Permission::where('slug', 'organisations.view')->pluck('id'));
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->roles()->attach($viewerRole);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/admin/organisations')->assertOk();
        $this->getJson("/api/v1/admin/organisations/{$organisation->id}")->assertOk()
            ->assertJsonMissingPath('data.organisation.payments_received');
        $this->getJson("/api/v1/admin/organisations/{$organisation->id}/financials")->assertForbidden();
        $this->getJson("/api/v1/admin/organisations/{$organisation->id}/members")->assertForbidden();
        $this->postJson('/api/v1/admin/organisations', ['name' => 'Blocked', 'owner_customer_id' => $owner->id])->assertForbidden();
        $this->putJson("/api/v1/admin/organisations/{$organisation->id}", ['name' => 'Blocked'])->assertForbidden();
        $this->postJson("/api/v1/admin/organisations/{$organisation->id}/archive")->assertForbidden();
        $this->postJson("/api/v1/admin/organisations/{$organisation->id}/restore")->assertForbidden();

        $stranger = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/admin/organisations')->assertForbidden();
        $this->getJson("/api/v1/admin/organisations/{$organisation->id}")->assertForbidden();
    }

    public function test_financial_overview_is_permission_gated_and_reads_existing_records(): void
    {
        $owner = $this->customer('0200000108');
        $organisation = $this->organisation($owner, 'Financial Organisation', Organisation::STATUS_ACTIVE);
        $this->invoice($organisation, '100.00');

        $response = $this->getJson("/api/v1/admin/organisations/{$organisation->id}/financials");

        $response->assertOk()
            ->assertJsonPath('data.currency', 'GHS')
            ->assertJsonPath('data.outstanding_invoice_count', 1)
            ->assertJsonPath('data.outstanding_invoices', '100.00');

        foreach (['payments_received', 'deposits_total', 'credit_balance', 'refunds_total'] as $key) {
            $this->assertArrayHasKey($key, $response->json('data'));
        }
    }

    public function test_members_listing_and_membership_status_changes(): void
    {
        $owner = $this->customer('0200000109');
        $organisation = $this->organisation($owner, 'Membership Organisation', Organisation::STATUS_ACTIVE);
        $member = $this->customer('0200000110');
        $membership = $organisation->memberships()->create([
            'customer_id' => $member->id,
            'role' => OrganisationMembership::ROLE_MEMBER,
            'status' => OrganisationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        $this->getJson("/api/v1/admin/organisations/{$organisation->id}/members")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.role', 'owner');

        $this->getJson("/api/v1/admin/organisations/{$organisation->id}/members?status=active")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->patchJson("/api/v1/admin/organisations/{$organisation->id}/members/{$membership->id}/status", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertSame(OrganisationMembership::STATUS_SUSPENDED, $membership->fresh()->status);

        $ownerMembership = $organisation->memberships()->where('role', OrganisationMembership::ROLE_OWNER)->firstOrFail();
        $this->patchJson("/api/v1/admin/organisations/{$organisation->id}/members/{$ownerMembership->id}/status", ['status' => 'removed'])
            ->assertStatus(409);

        $this->patchJson("/api/v1/admin/organisations/{$organisation->id}/members/{$membership->id}/status", ['status' => 'removed'])
            ->assertOk();

        // A removed membership is terminal; it cannot be reactivated.
        $this->patchJson("/api/v1/admin/organisations/{$organisation->id}/members/{$membership->id}/status", ['status' => 'active'])
            ->assertStatus(409);
    }

    public function test_detail_exposes_profile_membership_summary_and_audit_history(): void
    {
        $owner = $this->customer('0200000111');
        $organisation = $this->organisation($owner, 'History Organisation', Organisation::STATUS_ACTIVE);
        $this->putJson("/api/v1/admin/organisations/{$organisation->id}", ['name' => 'History Organisation Renamed'])->assertOk();
        $this->postJson("/api/v1/admin/organisations/{$organisation->id}/archive")->assertOk();

        $response = $this->getJson("/api/v1/admin/organisations/{$organisation->id}");

        $response->assertOk()
            ->assertJsonPath('data.organisation.membership.total', 1)
            ->assertJsonPath('data.organisation.status', 'archived')
            ->assertJsonPath('data.organisation.owner.id', $owner->id);

        $actions = array_column($response->json('data.activity'), 'action');
        $this->assertContains('organisation.updated', $actions);
        $this->assertContains('organisation.archived', $actions);
    }

    public function test_contact_candidates_only_return_active_customers(): void
    {
        $active = $this->customer('0200000112');
        $archived = $this->customer('0200000113');
        $archived->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        $response = $this->getJson('/api/v1/admin/organisations/contacts?search='.$active->phone);

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($active->id, $response->json('data.0.id'));
    }

    private function customer(string $phone): Customer
    {
        return Customer::create([
            'name' => 'Support Customer '.$phone,
            'phone' => $phone,
            'email' => $phone.'@example.test',
            'password' => 'test-password',
            'is_active' => true,
        ]);
    }

    private function organisation(Customer $owner, string $name, string $status): Organisation
    {
        $organisation = Organisation::create([
            'reference' => 'ORG-'.strtoupper((string) str()->ulid()),
            'owner_customer_id' => $owner->id,
            'name' => $name,
            'status' => $status,
        ]);

        $organisation->memberships()->create([
            'customer_id' => $owner->id,
            'role' => OrganisationMembership::ROLE_OWNER,
            'status' => OrganisationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        return $organisation;
    }

    private function invoice(Organisation $organisation, string $total = '25.00'): CommercialDocument
    {
        return CommercialDocument::create([
            'document_number' => 'INV-'.strtoupper((string) str()->ulid()),
            'document_type' => CommercialDocument::TYPE_INVOICE,
            'status' => CommercialDocument::STATUS_ISSUED,
            'customer_id' => $organisation->owner_customer_id,
            'organisation_id' => $organisation->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'GHS',
            'subtotal' => $total,
            'total' => $total,
            'issued_at' => now(),
        ]);
    }
}
