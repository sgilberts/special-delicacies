<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\CommercialDocument;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\Customer;
use App\Models\Organisation;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CommercialDocumentService;
use App\Services\CreditNoteService;
use App\Services\PaymentAllocationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Stage4cCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private User $creator;
    private User $approver;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create([
            'name' => 'Credit Note Customer', 'phone' => '0200000951', 'email' => 'creditnote@example.test',
            'password' => 'test-password', 'is_active' => true,
        ]);
        $this->creator = $this->userWith(['financials.credit_notes.view', 'financials.credit_notes.create', 'financials.credit_notes.submit', 'financials.credit_notes.apply', 'financials.credit_notes.cancel']);
        $this->approver = $this->userWith(['financials.credit_notes.view', 'financials.credit_notes.approve', 'financials.credit_notes.issue']);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_credit_note_totals_match_the_original_document_calculator(): void
    {
        $invoice = $this->issuedInvoice();
        $this->assertSame('136.75', (string) $invoice->total);
        $this->assertSame('135.00', (string) $invoice->subtotal);
        $this->assertSame('1.75', (string) $invoice->tax_amount);

        $service = app(CreditNoteService::class);
        $note = $service->create($this->creator, $this->fullCreditInput($invoice, 'Customer returned the entire order'));

        $this->assertSame(CreditNote::STATUS_DRAFT, $note->status);
        $this->assertMatchesRegularExpression('/^CN-\d{4}-\d{8}$/', $note->credit_note_number);
        $this->assertSame('135.00', (string) $note->subtotal);
        $this->assertSame('1.75', (string) $note->tax_amount);
        $this->assertSame('136.75', (string) $note->total);
        $this->assertSame($invoice->document_number, $note->original_document_number);
        $this->assertSame($invoice->customer_id, $note->customer_id);
        $this->assertCount(2, $note->lines);
        $this->assertSame('36.75', (string) $note->lines->firstWhere('description', 'Grilled chicken')->line_total);
    }

    public function test_partial_credit_note_and_remaining_creditable_amount(): void
    {
        $invoice = $this->issuedInvoice();
        $service = app(CreditNoteService::class);
        $line = $invoice->lines->firstWhere('description', 'Jollof rice');

        $note = $service->create($this->creator, [
            'commercial_document_id' => $invoice->id,
            'reason' => 'One portion of jollof rice was not delivered',
            'lines' => [[
                'commercial_document_line_id' => $line->id, 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => '0',
            ]],
        ]);
        $this->assertSame('50.00', (string) $note->total);
        $this->assertSame('136.75', PaymentAllocationService::formatMinorUnits($service->remainingCreditableMinor($invoice)));

        $service->submit($this->creator, $note);
        $this->assertSame('86.75', PaymentAllocationService::formatMinorUnits($service->remainingCreditableMinor($invoice)));
    }

    public function test_credit_note_lifecycle_separation_of_duties_and_issued_immutability(): void
    {
        $invoice = $this->issuedInvoice();
        $service = app(CreditNoteService::class);
        $note = $service->create($this->creator, $this->fullCreditInput($invoice, 'Full order returned'));

        try {
            $service->issue($this->approver, $note);
            $this->fail('A draft credit note cannot be issued directly.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('approved', $exception->getMessage());
        }

        $service->submit($this->creator, $note);
        try {
            $service->approve($this->creator, $note->fresh());
            $this->fail('The creator must not approve their own credit note.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different user', $exception->getMessage());
        }
        $approved = $service->approve($this->approver, $note->fresh());
        $this->assertSame(CreditNote::STATUS_APPROVED, $approved->status);

        $issued = $service->issue($this->approver, $approved);
        $this->assertSame(CreditNote::STATUS_ISSUED, $issued->status);
        $this->assertNotNull($issued->issued_at);
        $this->assertSame($this->approver->id, $issued->issued_by);

        try {
            $service->updateDraft($this->creator, $issued, ['reason' => 'Silent edit of an issued note']);
            $this->fail('An issued credit note is immutable.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('draft', $exception->getMessage());
        }
        try {
            $service->issue($this->approver, $issued);
            $this->fail('A credit note can only be issued once.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('approved', $exception->getMessage());
        }
        try {
            $service->cancel($this->creator, $issued, 'Attempt to delete an issued note');
            $this->fail('An issued credit note cannot be cancelled.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('issued', $exception->getMessage());
        }
    }

    public function test_credit_note_cannot_exceed_remaining_creditable_or_credit_a_line_twice(): void
    {
        $invoice = $this->issuedInvoice();
        $service = app(CreditNoteService::class);
        $jollof = $invoice->lines->firstWhere('description', 'Jollof rice');

        $first = $service->create($this->creator, [
            'commercial_document_id' => $invoice->id,
            'reason' => 'First partial credit for jollof rice',
            'lines' => [['commercial_document_line_id' => $jollof->id, 'quantity' => '2', 'unit_price' => '50.00', 'tax_rate' => '0']],
        ]);
        $service->submit($this->creator, $first);

        try {
            $service->create($this->creator, [
                'commercial_document_id' => $invoice->id,
                'reason' => 'Attempt to credit the same quantity again',
                'lines' => [['commercial_document_line_id' => $jollof->id, 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => '0']],
            ]);
            $this->fail('A credited line quantity cannot be credited twice.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('uncredited quantity', $exception->getMessage());
        }

        try {
            $service->create($this->creator, [
                'commercial_document_id' => $invoice->id,
                'reason' => 'Attempt to credit more than remains',
                'lines' => [['description' => 'Goodwill credit', 'quantity' => '1', 'unit_price' => '136.75', 'tax_rate' => '0']],
            ]);
            $this->fail('The credit note must not exceed the remaining creditable amount.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('remaining creditable', $exception->getMessage());
        }
    }

    public function test_only_issued_non_voided_invoices_can_be_credited(): void
    {
        $service = app(CreditNoteService::class);
        $draft = CommercialDocument::create([
            'document_number' => 'S4C-DRAFT-1', 'document_type' => CommercialDocument::TYPE_INVOICE,
            'status' => CommercialDocument::STATUS_DRAFT, 'customer_id' => $this->customer->id,
            'currency' => 'GHS', 'subtotal' => '10.00', 'total' => '10.00',
        ]);
        try {
            $service->create($this->creator, $this->fullCreditInput($draft, 'Draft invoice credit', false));
            $this->fail('A draft invoice cannot be credited.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('issued', $exception->getMessage());
        }

        $quotation = CommercialDocument::create([
            'document_number' => 'S4C-QT-1', 'document_type' => CommercialDocument::TYPE_QUOTATION,
            'status' => CommercialDocument::STATUS_ISSUED, 'customer_id' => $this->customer->id,
            'currency' => 'GHS', 'subtotal' => '10.00', 'total' => '10.00', 'issue_date' => today(), 'issued_at' => now(),
        ]);
        try {
            $service->create($this->creator, $this->fullCreditInput($quotation, 'Quotation credit', false));
            $this->fail('A quotation cannot be credited.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('invoices', $exception->getMessage());
        }

        $voided = $this->issuedInvoice('S4C-VOID-1');
        $voided->forceFill(['status' => CommercialDocument::STATUS_VOIDED, 'voided_at' => now()])->save();
        try {
            $service->create($this->creator, $this->fullCreditInput($voided, 'Voided invoice credit', false));
            $this->fail('A voided invoice cannot be credited.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('voided', $exception->getMessage());
        }
    }

    public function test_issuing_and_applying_a_credit_note_reduces_the_invoice_outstanding_balance(): void
    {
        $invoice = $this->issuedInvoice();
        $service = app(CreditNoteService::class);
        $allocations = app(PaymentAllocationService::class);
        $note = $this->issuedNote($service, $invoice, 'Partial credit applied to the invoice', 50.00);

        $this->assertSame('136.75', $allocations->balance($invoice)['outstanding_balance']);

        $partial = $service->apply($this->creator, $note, ['amount' => '30.00', 'operation_reference' => 's4c-credit-apply-0001']);
        $this->assertSame(CreditNote::STATUS_PARTIALLY_APPLIED, $partial->status);
        $summary = $allocations->balance($invoice);
        $this->assertSame('30.00', $summary['credit_note_reduction']);
        $this->assertSame('106.75', $summary['outstanding_balance']);

        $replay = $service->apply($this->creator, $note->fresh(), ['amount' => '30.00', 'operation_reference' => 's4c-credit-apply-0001']);
        $this->assertSame(1, CreditNoteApplication::count());
        $this->assertSame('30.00', $service->present($replay)['applied_amount']);

        $full = $service->apply($this->creator, $note->fresh(), ['amount' => '20.00', 'operation_reference' => 's4c-credit-apply-0002']);
        $this->assertSame(CreditNote::STATUS_FULLY_APPLIED, $full->status);
        $this->assertSame('86.75', $allocations->balance($invoice)['outstanding_balance']);

        try {
            $service->apply($this->creator, $note->fresh(), ['amount' => '1.00', 'operation_reference' => 's4c-credit-apply-0003']);
            $this->fail('A fully applied credit note cannot be applied again.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('issued', $exception->getMessage());
        }
    }

    public function test_application_cannot_exceed_the_invoice_outstanding_balance(): void
    {
        $invoice = $this->issuedInvoice();
        $service = app(CreditNoteService::class);
        $note = $this->issuedNote($service, $invoice, 'Credit for a fully settled invoice', 100.00);

        $payment = PaymentTransaction::create([
            'order_id' => null, 'customer_id' => $this->customer->id, 'organisation_id' => null,
            'payment_purpose' => 'invoice', 'transaction_reference' => 'S4C-CN-PAY-1',
            'provider' => 'cash', 'payment_method' => 'cash', 'amount' => '136.75', 'currency' => 'GHS',
            'status' => 'succeeded', 'verified_at' => now(), 'received_at' => now(), 'reporting_date' => now()->toDateString(),
        ]);
        app(PaymentAllocationService::class)->allocate($payment->id, [['commercial_document_id' => $invoice->id, 'amount' => '136.75']], 's4c-cn-allocation-1', $this->actor);
        $this->assertSame('0.00', app(PaymentAllocationService::class)->balance($invoice)['outstanding_balance']);

        try {
            $service->apply($this->creator, $note, ['amount' => '10.00', 'operation_reference' => 's4c-credit-apply-0011']);
            $this->fail('A credit note cannot be applied beyond the invoice outstanding balance.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('outstanding balance', $exception->getMessage());
        }
        $this->assertSame(CreditNote::STATUS_ISSUED, $note->fresh()->status);
    }

    public function test_credit_note_numbers_are_unique_and_audit_history_is_recorded(): void
    {
        $invoice = $this->issuedInvoice();
        $service = app(CreditNoteService::class);
        $first = $service->create($this->creator, $this->fullCreditInput($invoice, 'First credit note'));
        $second = $service->create($this->creator, $this->fullCreditInput($invoice, 'Second credit note'));
        $this->assertNotSame($first->credit_note_number, $second->credit_note_number);

        $service->submit($this->creator, $first);
        $service->approve($this->approver, $first->fresh());
        $service->issue($this->approver, $first->fresh());

        $actions = collect($service->history($first->fresh()))->pluck('action')->all();
        $this->assertContains('credit_note.created', $actions);
        $this->assertContains('credit_note.submitted', $actions);
        $this->assertContains('credit_note.approved', $actions);
        $this->assertContains('credit_note.issued', $actions);
        $this->assertSame(4, AdminAuditLog::where('entity_type', CreditNote::class)->where('entity_id', (string) $first->id)->count());
    }

    public function test_organisation_invoice_credit_note_keeps_the_organisation_boundary(): void
    {
        $organisation = Organisation::create([
            'reference' => 'S4C-ORG-1', 'owner_customer_id' => $this->customer->id, 'name' => 'Stage4C Organisation', 'status' => Organisation::STATUS_ACTIVE,
        ]);
        $this->customer->organisationMemberships()->create([
            'organisation_id' => $organisation->id, 'role' => 'owner', 'status' => 'active',
        ]);
        $invoice = $this->issuedInvoice('S4C-ORG-INV-1', $organisation);
        $service = app(CreditNoteService::class);
        $note = $service->create($this->creator, $this->fullCreditInput($invoice, 'Organisation order returned'));

        $this->assertSame($organisation->id, $note->organisation_id);
        $this->assertSame($this->customer->id, $note->customer_id);
        $this->assertSame($organisation->id, $note->fresh()->commercialDocument->organisation_id);
    }

    public function test_credit_note_endpoints_enforce_permissions(): void
    {
        $invoice = $this->issuedInvoice();
        $outsider = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/admin/credit-notes')->assertForbidden();
        $this->postJson('/api/v1/admin/credit-notes', $this->fullCreditInput($invoice, 'Unauthorised credit'))->assertForbidden();

        Sanctum::actingAs($this->creator);
        $created = $this->postJson('/api/v1/admin/credit-notes', $this->fullCreditInput($invoice, 'Authorised credit note'))->assertCreated();
        $noteId = $created->json('data.id');
        $this->getJson('/api/v1/admin/credit-notes')->assertOk();
        $this->getJson("/api/v1/admin/credit-notes/{$noteId}")->assertOk()->assertJsonPath('data.status', CreditNote::STATUS_DRAFT);
        $this->postJson("/api/v1/admin/credit-notes/{$noteId}/submit")->assertOk();
        $this->postJson("/api/v1/admin/credit-notes/{$noteId}/approve")->assertForbidden();
        $this->postJson("/api/v1/admin/credit-notes/{$noteId}/issue")->assertForbidden();

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/admin/credit-notes/{$noteId}/approve")->assertOk()->assertJsonPath('data.status', CreditNote::STATUS_APPROVED);
        $this->postJson("/api/v1/admin/credit-notes/{$noteId}/issue")->assertOk()->assertJsonPath('data.status', CreditNote::STATUS_ISSUED);
        $this->getJson("/api/v1/admin/credit-notes/{$noteId}/history")->assertOk()->assertJsonFragment(['action' => 'credit_note.issued']);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'Role '.uniqid(), 'slug' => 'role-'.uniqid()]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id'));
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function issuedInvoice(string $number = 'S4C-INV-100', ?Organisation $organisation = null): CommercialDocument
    {
        $service = app(CommercialDocumentService::class);
        $request = Request::create('/api/v1/admin/commercial-documents', 'POST');
        $document = $service->create($this->actor, [
            'document_type' => CommercialDocument::TYPE_INVOICE,
            'customer_id' => $this->customer->id,
            'organisation_id' => $organisation?->id,
            'lines' => [
                ['description' => 'Jollof rice', 'quantity' => '2', 'unit_price' => '50.00', 'tax_rate' => '0'],
                ['description' => 'Grilled chicken', 'quantity' => '1', 'unit_price' => '40.00', 'discount_amount' => '5.00', 'tax_rate' => '5'],
            ],
            'discount_amount' => '0',
        ], $request);
        // Keep deterministic numbers for assertions without changing the service.
        $document->forceFill(['document_number' => $number])->save();
        return $service->issue($this->actor, $document->fresh(['lines']), $request);
    }

    private function fullCreditInput(CommercialDocument $invoice, string $reason, bool $withLines = true): array
    {
        $input = ['commercial_document_id' => $invoice->id, 'reason' => $reason];
        if (! $withLines) return $input;
        $input['lines'] = $invoice->lines->map(fn ($line) => [
            'commercial_document_line_id' => $line->id,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'discount_amount' => (string) $line->discount_amount,
            'tax_rate' => $line->tax_rate !== null ? (string) $line->tax_rate : null,
        ])->values()->all();
        return $input;
    }

    private function issuedNote(CreditNoteService $service, CommercialDocument $invoice, string $reason, float $amount): CreditNote
    {
        $note = $service->create($this->creator, [
            'commercial_document_id' => $invoice->id,
            'reason' => $reason,
            'lines' => [[
                'description' => 'Goodwill credit', 'quantity' => '1',
                'unit_price' => number_format($amount, 2, '.', ''), 'tax_rate' => '0',
            ]],
        ]);
        $service->submit($this->creator, $note);
        $service->approve($this->approver, $note->fresh());
        return $service->issue($this->approver, $note->fresh());
    }
}
