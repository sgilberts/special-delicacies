<?php

namespace Tests\Feature;

use App\Models\CommercialDocument;
use App\Models\CommercialDocumentHistory;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommercialDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => 'Document Manager', 'slug' => 'document-manager']);
        $permissions = Permission::whereIn('slug', [
            'commercial_documents.view', 'commercial_documents.manage', 'commercial_documents.issue', 'commercial_documents.void',
        ])->pluck('id');
        $role->permissions()->sync($permissions);
        $this->staff = User::factory()->create(['is_active' => true]);
        $this->staff->roles()->attach($role);
        Sanctum::actingAs($this->staff);
    }

    public function test_staff_creates_individual_quotation_with_decimal_safe_totals_and_separate_numbering(): void
    {
        $customer = $this->customer('0200000001');
        $quote = $this->createDocument('quotation', $customer, [
            $this->line('Catering service', '1.500', '10.25', '1.38'),
            $this->line('Delivery', '2', '3.33', '0.00'),
        ], ['discount_amount' => '0.66']);

        $this->assertSame('QT-', substr($quote->document_number, 0, 3));
        $this->assertSame('20.66', $quote->subtotal);
        $this->assertSame('0.00', $quote->tax_amount);
        $this->assertSame('20.00', $quote->total);
        $this->assertSame(2, $quote->lines()->count());
        $this->assertSame('14.00', $quote->lines()->first()->line_subtotal);

        $invoice = $this->createDocument('invoice', $customer, [$this->line('Lunch', '1', '20.00')]);
        $this->assertStringStartsWith('INV-', $invoice->document_number);
        $this->assertNotSame($quote->document_number, $invoice->document_number);
    }

    public function test_catalog_price_and_description_are_snapshotted_and_client_totals_are_rejected(): void
    {
        $customer = $this->customer('0200000002');
        $category = MenuCategory::create(['name' => 'Catering', 'slug' => 'commercial-catering']);
        $product = MenuItem::create(['category_id' => $category->id, 'name' => 'Rice tray', 'slug' => 'rice-tray', 'description' => 'Original detail', 'price' => '42.75', 'is_available' => true]);
        $payload = [
            'document_type' => 'invoice', 'customer_id' => $customer->id,
            'lines' => [['menu_item_id' => $product->id, 'quantity' => '2', 'unit_price' => '0.01']],
            'subtotal' => '0.02', 'tax_amount' => '0', 'total' => '0.02',
        ];
        $this->postJson('/api/v1/admin/commercial-documents', $payload)->assertUnprocessable()->assertJsonValidationErrors(['subtotal', 'tax_amount', 'total']);
        unset($payload['subtotal'], $payload['tax_amount'], $payload['total']);
        $id = $this->postJson('/api/v1/admin/commercial-documents', $payload)->assertCreated()->json('data.id');
        $document = CommercialDocument::findOrFail($id);
        $this->assertSame('85.50', $document->total);
        $this->assertSame('Rice tray', $document->lines()->first()->description);
        $this->assertSame('42.75', $document->lines()->first()->unit_price);

        $product->update(['name' => 'Renamed rice', 'description' => 'New detail', 'price' => '99.99']);
        $this->postJson("/api/v1/admin/commercial-documents/{$id}/issue")->assertOk();
        $this->assertSame('Rice tray', $document->fresh()->lines()->first()->description);
        $this->assertSame('42.75', $document->fresh()->lines()->first()->unit_price);
        $this->patchJson("/api/v1/admin/commercial-documents/{$id}", ['customer_id' => $customer->id, 'lines' => [$this->line('Changed', '1', '1.00')]])->assertConflict();
    }

    public function test_organisation_documents_are_visible_to_members_but_cross_organisation_access_is_hidden(): void
    {
        $owner = $this->customer('0200000003');
        $member = $this->customer('0200000004');
        $other = $this->customer('0200000005');
        $organisation = $this->organisation($owner, 'Org Docs');
        $organisation->memberships()->create(['customer_id' => $member->id, 'role' => 'manager', 'status' => 'active', 'joined_at' => now()]);
        $otherOrganisation = $this->organisation($other, 'Other Docs');
        $document = $this->createDocument('quotation', $owner, [$this->line('Meal plan', '1', '120.00')], ['organisation_id' => $organisation->id]);
        $otherDocument = $this->createDocument('quotation', $other, [$this->line('Meal plan', '1', '120.00')], ['organisation_id' => $otherOrganisation->id]);
        $this->postJson("/api/v1/admin/commercial-documents/{$document->id}/issue")->assertOk();

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/customer/quotations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/customer/quotations/{$document->id}")->assertOk();
        $this->postJson("/api/v1/customer/quotations/{$document->id}/accept")->assertNotFound();
        $this->getJson("/api/v1/customer/quotations/{$otherDocument->id}")->assertNotFound();

        $membership = $organisation->memberships()->where('customer_id', $member->id)->firstOrFail();
        $membership->update(['status' => 'suspended']);
        $this->getJson("/api/v1/customer/quotations/{$document->id}")->assertNotFound();
    }

    public function test_customer_must_explicitly_accept_and_quote_expiry_is_audited(): void
    {
        $customer = $this->customer('0200000006');
        $quote = $this->createDocument('quotation', $customer, [$this->line('Catering', '1', '50.00')]);
        $this->postJson("/api/v1/admin/commercial-documents/{$quote->id}/issue")->assertOk();
        $this->assertSame('issued', $quote->fresh()->status);

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/customer/quotations/{$quote->id}")->assertOk();
        $this->postJson("/api/v1/customer/quotations/{$quote->id}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson("/api/v1/customer/quotations/{$quote->id}/accept")->assertConflict();
        $this->assertDatabaseHas('commercial_document_histories', ['commercial_document_id' => $quote->id, 'action' => 'accept', 'customer_id' => $customer->id]);

        Sanctum::actingAs($this->staff);
        $expiring = $this->createDocument('quotation', $customer, [$this->line('Catering', '1', '25.00')]);
        $this->postJson("/api/v1/admin/commercial-documents/{$expiring->id}/issue")->assertOk();
        $expiring->update(['expiry_date' => today()->subDay()]);
        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/customer/quotations/{$expiring->id}/accept")->assertConflict()->assertJsonPath('message', 'This quotation has expired.');
        $this->assertSame('expired', $expiring->fresh()->status);
        $this->assertDatabaseHas('commercial_document_histories', ['commercial_document_id' => $expiring->id, 'action' => 'expired']);
    }

    public function test_invoice_requires_explicit_audited_void_reason_and_remains_in_history(): void
    {
        $customer = $this->customer('0200000007');
        $invoice = $this->createDocument('invoice', $customer, [$this->line('Lunch', '1', '80.00')]);
        $this->postJson("/api/v1/admin/commercial-documents/{$invoice->id}/issue")->assertOk();
        $this->postJson("/api/v1/admin/commercial-documents/{$invoice->id}/void", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/commercial-documents/{$invoice->id}/void", ['reason' => 'Duplicate invoice'])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->assertSame('Duplicate invoice', $invoice->fresh()->void_reason);
        $this->assertDatabaseHas('commercial_document_histories', ['commercial_document_id' => $invoice->id, 'action' => 'void']);
        $this->postJson("/api/v1/admin/commercial-documents/{$invoice->id}/void", ['reason' => 'Again'])->assertConflict();
    }

    public function test_staff_without_document_permissions_cannot_access_admin_api(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'No Documents', 'slug' => 'no-documents']);
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/commercial-documents')->assertForbidden();
    }

    public function test_invalid_quantities_tax_rates_and_cross_customer_identifiers_are_rejected(): void
    {
        $customer = $this->customer('0200000008');
        $other = $this->customer('0200000009');
        $this->postJson('/api/v1/admin/commercial-documents', [
            'document_type' => 'quotation', 'customer_id' => $customer->id,
            'lines' => [['description' => 'Invalid', 'quantity' => '-1', 'unit_price' => '10.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
        $this->postJson('/api/v1/admin/commercial-documents', [
            'document_type' => 'quotation', 'customer_id' => $customer->id,
            'lines' => [['description' => 'Tax not configured', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '15.0000']],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.tax_rate');

        $document = $this->createDocument('invoice', $other, [$this->line('Private invoice', '1', '12.00')]);
        $this->postJson("/api/v1/admin/commercial-documents/{$document->id}/issue")->assertOk();
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/customer/invoices/{$document->id}")->assertNotFound();
    }

    public function test_customer_cannot_access_document_through_the_wrong_document_type_route(): void
    {
        $customer = $this->customer('0200000010');
        $invoice = $this->createDocument('invoice', $customer, [$this->line('Meal', '1', '10.00')]);
        $this->postJson("/api/v1/admin/commercial-documents/{$invoice->id}/issue")->assertOk();
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/customer/quotations/{$invoice->id}")->assertNotFound();
    }

    private function createDocument(string $type, Customer $customer, array $lines, array $extra = []): CommercialDocument
    {
        $response = $this->postJson('/api/v1/admin/commercial-documents', array_merge([
            'document_type' => $type,
            'customer_id' => $customer->id,
            'lines' => $lines,
        ], $extra));
        $response->assertCreated();
        return CommercialDocument::findOrFail($response->json('data.id'));
    }

    private function customer(string $phone): Customer
    {
        return Customer::create(['name' => 'Commercial Customer', 'phone' => $phone, 'email' => $phone.'@example.test', 'password' => 'test-password', 'is_active' => true]);
    }

    private function organisation(Customer $owner, string $name): Organisation
    {
        $organisation = Organisation::create(['reference' => 'ORG-'.strtoupper(str()->ulid()), 'owner_customer_id' => $owner->id, 'name' => $name, 'status' => 'active']);
        $organisation->memberships()->create(['customer_id' => $owner->id, 'role' => OrganisationMembership::ROLE_OWNER, 'status' => OrganisationMembership::STATUS_ACTIVE, 'joined_at' => now()]);
        return $organisation;
    }

    private function line(string $description, string $quantity, string $unitPrice, string $discount = '0.00'): array
    {
        return ['description' => $description, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'discount_amount' => $discount];
    }
}
