<?php

namespace Tests\Feature;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\CustomerCreditLedgerEntry;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CreditNoteService;
use App\Services\CustomerCreditService;
use App\Services\PaymentAllocationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Stage4cCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private User $creator;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create([
            'name' => 'Stage 4C Credit Note Customer', 'phone' => '0200000902', 'email' => 'stage4c-creditnote@example.test',
            'password' => 'old-customer-password', 'is_active' => true,
        ]);
        $this->creator = User::factory()->create(['is_active' => true, 'name' => 'Credit Note Creator']);
        $this->approver = User::factory()->create(['is_active' => true, 'name' => 'Credit Note Approver']);
    }

    public function test_full_credit_note_auto_applies_and_never_mutates_the_invoice(): void
    {
        $invoice = $this->invoice('200.00', 'STAGE4C-CN-INV-1');
        $service = app(CreditNoteService::class);

        $note = $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '200.00', 'notes' => 'Full credit']);
        $this->assertSame('draft', $note->status);
        $this->assertStringStartsWith('CN-', $note->document_number);

        $issued = $service->issue($this->approver, $note->fresh());
        $this->assertSame('issued', $issued->status);

        $credit = CustomerCreditLedgerEntry::where('entry_type', 'credit_note_credit')->sole();
        $this->assertSame('200.00', $credit->amount);
        $this->assertSame((int) $note->id, (int) $credit->commercial_document_id);
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits(app(PaymentAllocationService::class)->invoiceOutstandingMinor($invoice->fresh())));
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits(app(CustomerCreditService::class)->balanceMinor($this->customer, 'GHS')));

        $fresh = $invoice->fresh();
        $this->assertSame('200.00', $fresh->total);
        $this->assertSame('issued', $fresh->status);
        $this->assertSame('200.00', app(PaymentAllocationService::class)->balance($fresh)['invoice_total']);
    }

    public function test_partial_credit_note_reduces_outstanding_by_the_credited_amount(): void
    {
        $invoice = $this->invoice('200.00', 'STAGE4C-CN-INV-2');
        $service = app(CreditNoteService::class);
        $note = $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '50.00']);
        $service->issue($this->approver, $note->fresh());

        $balance = app(PaymentAllocationService::class)->balance($invoice->fresh());
        $this->assertSame('50.00', $balance['credit_applied_amount']);
        $this->assertSame('150.00', $balance['outstanding_balance']);
        $this->assertSame('200.00', $balance['invoice_total']);
    }

    public function test_invalid_originating_documents_are_rejected(): void
    {
        $service = app(CreditNoteService::class);
        $quotation = CommercialDocument::create([
            'document_number' => 'STAGE4C-CN-QT-1', 'document_type' => 'quotation', 'status' => 'issued',
            'customer_id' => $this->customer->id, 'currency' => 'GHS', 'subtotal' => '50.00',
            'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => '50.00', 'issue_date' => today(), 'issued_at' => now(),
        ]);
        $draft = $this->invoice('50.00', 'STAGE4C-CN-INV-3', ['status' => 'draft', 'issued_at' => null]);
        $voided = $this->invoice('50.00', 'STAGE4C-CN-INV-4', ['status' => 'voided', 'voided_at' => now()]);

        foreach ([$quotation, $draft, $voided] as $document) {
            try {
                $service->create($this->creator, ['originating_document_id' => $document->id, 'amount' => '10.00']);
                $this->fail('An ineligible document must not be creditable.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('invoices', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_duplicate_or_excess_credit_notes_are_prevented(): void
    {
        $invoice = $this->invoice('200.00', 'STAGE4C-CN-INV-5');
        $service = app(CreditNoteService::class);
        $first = $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '200.00']);
        $second = $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '200.00']);

        $service->issue($this->approver, $first->fresh());
        $service->issue($this->approver, $first->fresh()); // idempotent
        $this->assertSame(1, CustomerCreditLedgerEntry::where('entry_type', 'credit_note_credit')->count());

        $this->expectException(DomainException::class);
        $service->issue($this->approver, $second->fresh());
    }

    public function test_creator_cannot_issue_their_own_credit_note(): void
    {
        $invoice = $this->invoice('100.00', 'STAGE4C-CN-INV-6');
        $service = app(CreditNoteService::class);
        $note = $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '100.00']);

        $this->expectException(DomainException::class);
        $service->issue($this->creator, $note->fresh());
    }

    public function test_credit_note_respects_existing_allocations_without_double_counting(): void
    {
        $invoice = $this->invoice('200.00', 'STAGE4C-CN-INV-7');
        $receipt = PaymentTransaction::create([
            'order_id' => null, 'customer_id' => $this->customer->id, 'organisation_id' => null, 'payment_purpose' => 'invoice',
            'transaction_reference' => 'STAGE4C-CN-PAY-1', 'payment_method' => 'cash', 'amount' => '80.00', 'currency' => 'GHS',
            'status' => 'succeeded', 'verified_at' => now(), 'received_at' => now(), 'reporting_date' => now()->toDateString(),
        ]);
        app(PaymentAllocationService::class)->allocate($receipt->id, [['commercial_document_id' => $invoice->id, 'amount' => '80.00']], 'stage4c-cn-alloc-1', $this->creator);

        $service = app(CreditNoteService::class);
        $note = $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '50.00']);
        $service->issue($this->approver, $note->fresh());

        $balance = app(PaymentAllocationService::class)->balance($invoice->fresh());
        $this->assertSame('80.00', $balance['allocated_amount']);
        $this->assertSame('50.00', $balance['credit_applied_amount']);
        $this->assertSame('130.00', $balance['settled_amount']);
        $this->assertSame('70.00', $balance['outstanding_balance']);
    }

    public function test_credit_note_cannot_exceed_remaining_creditable_amount(): void
    {
        $invoice = $this->invoice('100.00', 'STAGE4C-CN-INV-8');
        $service = app(CreditNoteService::class);
        $note = $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '100.00']);
        $service->issue($this->approver, $note->fresh());

        $this->expectException(DomainException::class);
        $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '1.00']);
    }

    public function test_credit_note_writes_commercial_document_history(): void
    {
        $invoice = $this->invoice('100.00', 'STAGE4C-CN-INV-9');
        $service = app(CreditNoteService::class);
        $note = $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '100.00']);
        $service->issue($this->approver, $note->fresh());

        $actions = CommercialDocument::with('histories')->find($note->id)->histories->pluck('action')->all();
        $this->assertContains('created', $actions);
        $this->assertContains('issued', $actions);
    }

    public function test_unused_credit_note_can_be_voided_but_used_credit_cannot(): void
    {
        config(['stage4c.credit_notes.auto_apply' => false]);
        $invoice = $this->invoice('100.00', 'STAGE4C-CN-INV-10');
        $service = app(CreditNoteService::class);
        $note = $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '40.00']);
        $service->issue($this->approver, $note->fresh());
        $this->assertSame('40.00', PaymentAllocationService::formatMinorUnits(app(CustomerCreditService::class)->balanceMinor($this->customer, 'GHS')));

        $voided = $service->void($this->approver, $note->fresh(), 'Issued in error');
        $this->assertSame('voided', $voided->status);
        $this->assertSame(1, CustomerCreditLedgerEntry::where('entry_type', 'reversal')->count());
        $this->assertSame('0.00', PaymentAllocationService::formatMinorUnits(app(CustomerCreditService::class)->balanceMinor($this->customer, 'GHS')));

        config(['stage4c.credit_notes.auto_apply' => true]);
        $used = $service->create($this->creator, ['originating_document_id' => $invoice->id, 'amount' => '10.00']);
        $service->issue($this->approver, $used->fresh());
        $this->expectException(DomainException::class);
        $service->void($this->approver, $used->fresh(), 'Attempt to void used credit');
    }

    public function test_credit_note_endpoints_enforce_permissions_and_customer_isolation(): void
    {
        $invoice = $this->invoice('100.00', 'STAGE4C-CN-INV-11');

        $viewer = $this->staffWith(['financials.credit_notes.view']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/admin/financials/credit-notes')->assertOk();
        $this->getJson('/api/v1/admin/financials/credit-notes/eligible-invoices')->assertOk();
        $this->postJson('/api/v1/admin/financials/credit-notes', ['originating_document_id' => $invoice->id, 'amount' => '10.00'])->assertForbidden();

        $creator = $this->staffWith(['financials.credit_notes.create']);
        Sanctum::actingAs($creator);
        $created = $this->postJson('/api/v1/admin/financials/credit-notes', ['originating_document_id' => $invoice->id, 'amount' => '10.00'])
            ->assertCreated()->json('data');
        $this->postJson('/api/v1/admin/financials/credit-notes/'.$created['id'].'/issue')->assertForbidden();

        $this->customer->createToken('customer-session');
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/admin/financials/credit-notes')->assertForbidden();
    }

    private function invoice(string $total, string $number, array $overrides = []): CommercialDocument
    {
        return CommercialDocument::create(array_merge([
            'document_number' => $number, 'document_type' => 'invoice', 'status' => 'issued',
            'customer_id' => $this->customer->id, 'currency' => 'GHS', 'subtotal' => $total,
            'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => $total,
            'issue_date' => today(), 'issued_at' => now(),
        ], $overrides));
    }

    private function staffWith(array $slugs): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'Role '.uniqid(), 'slug' => 'role-'.uniqid()]);
        foreach ($slugs as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'description' => $slug]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);
        return $user;
    }
}
