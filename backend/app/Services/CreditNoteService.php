<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\CommercialDocument;
use App\Models\CommercialDocumentLine;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\CreditNoteLine;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stage 4C credit note domain.
 *
 * A credit note is a commercial document that reduces a previously issued
 * invoice. It is not a payment, not a refund and not customer credit: issuing
 * one never moves money and never posts to the Stage 4B ledger.
 *
 * Line arithmetic mirrors the existing Stage 3 document calculator exactly
 * (integer minor units, three-decimal quantities, four-decimal tax rates) so a
 * credit note can never disagree with the invoice it credits.
 */
class CreditNoteService
{
    public function __construct(
        private readonly PaymentAllocationService $allocations,
        private readonly AuditService $audit,
    ) {}

    public function eligibleDocuments(array $filters = [])
    {
        $effective = implode("','", CreditNote::EFFECTIVE_STATUSES);
        $query = CommercialDocument::query()
            ->with(['customer:id,name', 'organisation:id,name'])
            ->where('document_type', CommercialDocument::TYPE_INVOICE)
            ->where('status', CommercialDocument::STATUS_ISSUED)->whereNotNull('issued_at')
            ->whereNull('voided_at')->where('total', '>', 0)
            ->whereRaw("commercial_documents.total > COALESCE((SELECT SUM(cn.total) FROM credit_notes cn WHERE cn.commercial_document_id = commercial_documents.id AND cn.status IN ('{$effective}')), 0)")
            ->orderByDesc('issue_date')->orderByDesc('id');

        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn ($search) => $search
                ->where('document_number', 'like', $term)
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $term))
                ->orWhereHas('organisation', fn ($organisation) => $organisation->where('name', 'like', $term)));
        }

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (CommercialDocument $document) => $this->documentRow($document));
    }

    public function documentContext(int $documentId): array
    {
        $document = CommercialDocument::query()->with(['lines', 'customer:id,name,email', 'organisation:id,name,reference'])->findOrFail($documentId);
        $this->assertCreditableDocument($document);

        $notes = CreditNote::query()->with(['creator:id,name', 'approver:id,name', 'issuer:id,name'])
            ->where('commercial_document_id', $document->id)->orderByDesc('id')->get();
        $creditedQuantities = $this->creditedQuantitiesForDocument($document->id);

        return $this->documentRow($document) + [
            'lines' => $document->lines->map(fn (CommercialDocumentLine $line) => [
                'id' => $line->id,
                'description' => $line->description,
                'unit_of_measure' => $line->unit_of_measure,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'line_subtotal' => $line->line_subtotal,
                'tax_rate' => $line->tax_rate,
                'tax_amount' => $line->tax_amount,
                'line_total' => $line->line_total,
                'credited_quantity' => number_format(($creditedQuantities[$line->id] ?? 0) / 1000, 3, '.', ''),
            ])->values(),
            'credit_notes' => $notes->map(fn (CreditNote $note) => $this->rowView($note))->values(),
        ];
    }

    public function list(array $filters = [])
    {
        $query = CreditNote::query()
            ->with(['customer:id,name', 'organisation:id,name', 'commercialDocument:id,document_number',
                'creator:id,name', 'approver:id,name', 'issuer:id,name'])
            ->latest('id');
        if (filled($filters['status'] ?? null)) $query->where('status', $filters['status']);
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn ($search) => $search
                ->where('credit_note_number', 'like', $term)
                ->orWhere('original_document_number', 'like', $term)
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $term))
                ->orWhereHas('organisation', fn ($organisation) => $organisation->where('name', 'like', $term)));
        }

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (CreditNote $note) => $this->rowView($note));
    }

    public function present(CreditNote $note): array
    {
        $note->loadMissing(['lines.originalLine', 'applications.actor:id,name', 'customer:id,name,email,phone',
            'organisation:id,name,reference', 'commercialDocument:id,document_number,issue_date,currency,total,status',
            'creator:id,name', 'approver:id,name', 'issuer:id,name']);

        $applied = $this->appliedMinor($note);
        $total = PaymentAllocationService::toMinorUnits((string) $note->total);

        return $this->rowView($note) + [
            'reason' => $note->reason,
            'notes' => $note->notes,
            'original_document_date' => $note->original_document_date?->toDateString(),
            'issue_date' => $note->issue_date?->toDateString(),
            'subtotal' => $note->subtotal,
            'discount_amount' => $note->discount_amount,
            'discount_description' => $note->discount_description,
            'tax_amount' => $note->tax_amount,
            'total' => $note->total,
            'applied_amount' => PaymentAllocationService::formatMinorUnits($applied),
            'remaining_amount' => PaymentAllocationService::formatMinorUnits(max(0, $total - $applied)),
            'remaining_creditable' => $note->commercialDocument
                ? PaymentAllocationService::formatMinorUnits($this->remainingCreditableMinor($note->commercialDocument, $note->id))
                : '0.00',
            'original_document_outstanding' => $note->commercialDocument
                ? PaymentAllocationService::formatMinorUnits($this->allocations->invoiceOutstandingMinor($note->commercialDocument))
                : '0.00',
            'lines' => $note->lines->map(fn (CreditNoteLine $line) => [
                'id' => $line->id,
                'original_line_id' => $line->commercial_document_line_id,
                'original_description' => $line->originalLine?->description,
                'description' => $line->description,
                'detail' => $line->detail,
                'unit_of_measure' => $line->unit_of_measure,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'line_subtotal' => $line->line_subtotal,
                'tax_rate' => $line->tax_rate,
                'tax_amount' => $line->tax_amount,
                'line_total' => $line->line_total,
            ])->values(),
            'applications' => $note->applications->map(fn (CreditNoteApplication $application) => [
                'id' => $application->id,
                'amount' => $application->amount,
                'currency' => $application->currency,
                'operation_reference' => $application->operation_reference,
                'invoice_outstanding_before' => $application->invoice_outstanding_before,
                'invoice_outstanding_after' => $application->invoice_outstanding_after,
                'applied_by' => $application->applied_by_name_snapshot ?: $application->actor?->name,
                'applied_at' => $application->applied_at,
            ])->values(),
            'created_by' => $note->created_by_name_snapshot ?: $note->creator?->name,
            'approved_by' => $note->approved_by_name_snapshot ?: $note->approver?->name,
            'issued_by' => $note->issued_by_name_snapshot ?: $note->issuer?->name,
            'submitted_at' => $note->submitted_at,
            'approved_at' => $note->approved_at,
            'issued_at' => $note->issued_at,
            'cancelled_at' => $note->cancelled_at,
            'cancellation_reason' => $note->cancellation_reason,
            'created_at' => $note->created_at,
            'updated_at' => $note->updated_at,
        ];
    }

    public function history(CreditNote $note): array
    {
        return AdminAuditLog::query()->with('user:id,name')
            ->where('entity_type', CreditNote::class)->where('entity_id', (string) $note->id)
            ->orderBy('id')->get()
            ->map(fn (AdminAuditLog $log) => [
                'action' => $log->action,
                'description' => $log->description,
                'actor' => $log->user?->name ?? 'System',
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at,
            ])->values()->all();
    }

    public function create(User $actor, array $input, ?Request $request = null): CreditNote
    {
        return DB::transaction(function () use ($actor, $input, $request): CreditNote {
            $document = CommercialDocument::query()->lockForUpdate()->find((int) $input['commercial_document_id']);
            if (! $document) throw new DomainException('The original commercial document could not be found.');
            $this->assertCreditableDocument($document);

            $reason = trim((string) $input['reason']);
            if (mb_strlen($reason) < 3) throw new DomainException('A reason for the credit is required.');

            $note = CreditNote::query()->create([
                'status' => CreditNote::STATUS_DRAFT,
                'customer_id' => $document->customer_id,
                'organisation_id' => $document->organisation_id,
                'commercial_document_id' => $document->id,
                'original_document_number' => $document->document_number,
                'original_document_date' => $document->issue_date,
                'currency' => $document->currency,
                'reason' => $reason,
                'notes' => filled($input['notes'] ?? null) ? trim((string) $input['notes']) : null,
                'created_by' => $actor->id,
                'created_by_name_snapshot' => $actor->name,
                'updated_by' => $actor->id,
            ]);
            $note->forceFill(['credit_note_number' => sprintf('CN-%s-%08d', now()->format('Y'), $note->id)])->save();
            $this->replaceLinesAndTotals($note, $document, $input);
            $note->save();

            $this->record('credit_note.created', $note, $actor, "Credit note {$note->credit_note_number} drafted for {$document->document_number}.", null, [
                'status' => $note->status, 'total' => $note->total, 'currency' => $note->currency,
            ], $request);

            return $note->fresh(['lines', 'customer', 'organisation', 'commercialDocument']);
        }, 3);
    }

    public function updateDraft(User $actor, CreditNote $note, array $input, ?Request $request = null): CreditNote
    {
        return DB::transaction(function () use ($note, $actor, $input, $request): CreditNote {
            $locked = CreditNote::query()->lockForUpdate()->findOrFail($note->id);
            if ($locked->status !== CreditNote::STATUS_DRAFT) {
                throw new DomainException('Only a draft credit note can be edited.');
            }
            $document = CommercialDocument::query()->lockForUpdate()->findOrFail($locked->commercial_document_id);
            if (filled($input['reason'] ?? null)) {
                $reason = trim((string) $input['reason']);
                if (mb_strlen($reason) < 3) throw new DomainException('A reason for the credit is required.');
                $locked->reason = $reason;
            }
            if (array_key_exists('notes', $input)) {
                $locked->notes = filled($input['notes']) ? trim((string) $input['notes']) : null;
            }
            $locked->updated_by = $actor->id;
            if (isset($input['lines'])) $this->replaceLinesAndTotals($locked, $document, $input);
            $locked->save();

            $this->record('credit_note.updated', $locked, $actor, "Draft credit note {$locked->credit_note_number} updated.", null, [
                'total' => $locked->total,
            ], $request);

            return $locked->fresh(['lines', 'customer', 'organisation', 'commercialDocument']);
        }, 3);
    }

    public function submit(User $actor, CreditNote $note, ?Request $request = null): CreditNote
    {
        return DB::transaction(function () use ($note, $actor, $request): CreditNote {
            $locked = CreditNote::query()->lockForUpdate()->with('lines')->findOrFail($note->id);
            if ($locked->status !== CreditNote::STATUS_DRAFT) throw new DomainException('Only a draft credit note can be submitted.');
            if ($locked->lines->isEmpty()) throw new DomainException('A credit note must contain at least one line item.');
            $this->assertWithinRemainingCredit($locked);
            $locked->forceFill([
                'status' => CreditNote::STATUS_SUBMITTED, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'updated_by' => $actor->id,
            ])->save();
            $this->record('credit_note.submitted', $locked, $actor, "Credit note {$locked->credit_note_number} submitted for approval.", ['status' => CreditNote::STATUS_DRAFT], ['status' => $locked->status], $request);
            return $locked->fresh(['lines']);
        }, 3);
    }

    public function approve(User $actor, CreditNote $note, ?Request $request = null): CreditNote
    {
        return DB::transaction(function () use ($note, $actor, $request): CreditNote {
            $locked = CreditNote::query()->lockForUpdate()->findOrFail($note->id);
            if ($locked->status !== CreditNote::STATUS_SUBMITTED) throw new DomainException('Only a submitted credit note can be approved.');
            if ((int) $locked->created_by === (int) $actor->id) {
                throw new DomainException('A credit note must be approved by a different user from the one who created it.');
            }
            $locked->forceFill([
                'status' => CreditNote::STATUS_APPROVED, 'approved_by' => $actor->id,
                'approved_by_name_snapshot' => $actor->name, 'approved_at' => now(),
            ])->save();
            $this->record('credit_note.approved', $locked, $actor, "Credit note {$locked->credit_note_number} approved.", ['status' => CreditNote::STATUS_SUBMITTED], ['status' => $locked->status], $request);
            return $locked->fresh();
        }, 3);
    }

    public function issue(User $actor, CreditNote $note, ?Request $request = null): CreditNote
    {
        return DB::transaction(function () use ($note, $actor, $request): CreditNote {
            $locked = CreditNote::query()->lockForUpdate()->with('lines')->findOrFail($note->id);
            if ($locked->status !== CreditNote::STATUS_APPROVED) throw new DomainException('Only an approved credit note can be issued.');
            if ($locked->lines->isEmpty()) throw new DomainException('A credit note must contain at least one line item.');
            $this->assertWithinRemainingCredit($locked);
            $locked->forceFill([
                'status' => CreditNote::STATUS_ISSUED, 'issue_date' => now()->toDateString(), 'issued_at' => now(),
                'issued_by' => $actor->id, 'issued_by_name_snapshot' => $actor->name,
            ])->save();
            $this->record('credit_note.issued', $locked, $actor, "Credit note {$locked->credit_note_number} issued.", ['status' => CreditNote::STATUS_APPROVED], ['status' => $locked->status], $request);
            return $locked->fresh(['lines']);
        }, 3);
    }

    public function apply(User $actor, CreditNote $note, array $input, ?Request $request = null): CreditNote
    {
        $minor = PaymentAllocationService::toMinorUnits((string) $input['amount']);
        if ($minor <= 0) throw new DomainException('The application amount must be greater than zero.');
        $reference = (string) $input['operation_reference'];
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $reference)) throw new DomainException('The application operation reference is invalid.');

        return DB::transaction(function () use ($note, $actor, $minor, $reference, $request): CreditNote {
            $locked = CreditNote::query()->lockForUpdate()->with('lines')->findOrFail($note->id);
            $document = CommercialDocument::query()->lockForUpdate()->findOrFail($locked->commercial_document_id);

            $existing = CreditNoteApplication::query()->where('operation_reference', $reference)->first();
            if ($existing) {
                if ((int) $existing->credit_note_id === (int) $locked->id
                    && (int) $existing->applied_by === (int) $actor->id
                    && PaymentAllocationService::toMinorUnits((string) $existing->amount) === $minor) {
                    return $locked->fresh(['lines', 'applications']);
                }
                throw new DomainException('This application reference was already used for a different credit note.');
            }

            if (! in_array($locked->status, CreditNote::APPLICABLE_STATUSES, true)) {
                throw new DomainException('Only an issued credit note can be applied.');
            }
            $total = PaymentAllocationService::toMinorUnits((string) $locked->total);
            $appliedBefore = $this->appliedMinor($locked);
            $remaining = $total - $appliedBefore;
            if ($minor > $remaining) {
                throw new DomainException('The application exceeds the credit note remaining amount of '.PaymentAllocationService::formatMinorUnits($remaining).'.');
            }
            $outstandingBefore = $this->allocations->invoiceOutstandingMinor($document);
            if ($minor > $outstandingBefore) {
                throw new DomainException('The application exceeds the original document outstanding balance of '.PaymentAllocationService::formatMinorUnits($outstandingBefore).'.');
            }

            CreditNoteApplication::query()->create([
                'credit_note_id' => $locked->id,
                'commercial_document_id' => $document->id,
                'operation_reference' => $reference,
                'amount' => PaymentAllocationService::formatMinorUnits($minor),
                'currency' => $locked->currency,
                'applied_by' => $actor->id,
                'applied_by_snapshot' => $actor->id,
                'applied_by_name_snapshot' => $actor->name,
                'applied_by_reference_snapshot' => 'user:'.$actor->id,
                'invoice_outstanding_before' => PaymentAllocationService::formatMinorUnits($outstandingBefore),
                'invoice_outstanding_after' => PaymentAllocationService::formatMinorUnits($outstandingBefore - $minor),
                'credit_note_applied_before' => PaymentAllocationService::formatMinorUnits($appliedBefore),
                'credit_note_applied_after' => PaymentAllocationService::formatMinorUnits($appliedBefore + $minor),
                'applied_at' => now(),
            ]);

            $appliedAfter = $appliedBefore + $minor;
            $locked->forceFill([
                'status' => $appliedAfter >= $total ? CreditNote::STATUS_FULLY_APPLIED : CreditNote::STATUS_PARTIALLY_APPLIED,
                'updated_by' => $actor->id,
            ])->save();

            $this->record('credit_note.applied', $locked, $actor, "Credit note {$locked->credit_note_number} applied for {$locked->currency} ".PaymentAllocationService::formatMinorUnits($minor).'.', null, [
                'amount' => PaymentAllocationService::formatMinorUnits($minor),
                'invoice_outstanding_before' => PaymentAllocationService::formatMinorUnits($outstandingBefore),
                'invoice_outstanding_after' => PaymentAllocationService::formatMinorUnits($outstandingBefore - $minor),
            ], $request);

            return $locked->fresh(['lines', 'applications']);
        }, 3);
    }

    public function cancel(User $actor, CreditNote $note, string $reason, ?Request $request = null): CreditNote
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new DomainException('A cancellation reason is required.');
        return DB::transaction(function () use ($note, $actor, $reason, $request): CreditNote {
            $locked = CreditNote::query()->lockForUpdate()->findOrFail($note->id);
            if (! in_array($locked->status, [CreditNote::STATUS_DRAFT, CreditNote::STATUS_SUBMITTED, CreditNote::STATUS_APPROVED], true)) {
                throw new DomainException('An issued credit note cannot be cancelled. Record an auditable correction instead.');
            }
            $old = $locked->status;
            $locked->forceFill([
                'status' => CreditNote::STATUS_CANCELLED, 'cancelled_by' => $actor->id,
                'cancelled_at' => now(), 'cancellation_reason' => $reason,
            ])->save();
            $this->record('credit_note.cancelled', $locked, $actor, "Credit note {$locked->credit_note_number} cancelled: {$reason}", ['status' => $old], ['status' => $locked->status, 'reason' => $reason], $request);
            return $locked->fresh();
        }, 3);
    }

    public function remainingCreditableMinor(CommercialDocument $document, ?int $excludeNoteId = null): int
    {
        $query = CreditNote::query()->where('commercial_document_id', $document->id)->whereIn('status', CreditNote::EFFECTIVE_STATUSES);
        if ($excludeNoteId !== null) $query->whereKeyNot($excludeNoteId);
        $credited = PaymentAllocationService::toMinorUnits((string) ($query->sum('total') ?: '0'));
        return max(0, PaymentAllocationService::toMinorUnits((string) $document->total) - $credited);
    }

    private function assertWithinRemainingCredit(CreditNote $note): void
    {
        $document = CommercialDocument::query()->lockForUpdate()->findOrFail($note->commercial_document_id);
        $this->assertCreditableDocument($document);
        $remaining = $this->remainingCreditableMinor($document, $note->id);
        $total = PaymentAllocationService::toMinorUnits((string) $note->total);
        if ($total <= 0) throw new DomainException('A credit note must have a total greater than zero.');
        if ($total > $remaining) {
            throw new DomainException('The credit note exceeds the remaining creditable amount of '.PaymentAllocationService::formatMinorUnits($remaining).'.');
        }
    }

    private function assertCreditableDocument(CommercialDocument $document): void
    {
        if ($document->document_type !== CommercialDocument::TYPE_INVOICE) {
            throw new DomainException('Only invoices can be credited.');
        }
        if ($document->status !== CommercialDocument::STATUS_ISSUED || ! $document->issued_at || $document->voided_at) {
            throw new DomainException('Only issued, non-voided invoices can be credited.');
        }
        if (PaymentAllocationService::toMinorUnits((string) $document->total) <= 0) {
            throw new DomainException('The invoice has no creditable value.');
        }
    }

    private function replaceLinesAndTotals(CreditNote $note, CommercialDocument $document, array $input): void
    {
        $lines = $input['lines'] ?? [];
        if ($lines === [] || count($lines) > 50) throw new DomainException('Provide between one and fifty credit note lines.');

        $credited = $this->creditedQuantitiesForDocument($document->id, $note->exists ? $note->id : null);
        $snapshots = [];
        $subtotal = 0;
        $taxTotal = 0;

        foreach (array_values($lines) as $position => $line) {
            $original = null;
            if (! empty($line['commercial_document_line_id'])) {
                $original = CommercialDocumentLine::query()->find((int) $line['commercial_document_line_id']);
                if (! $original || (int) $original->commercial_document_id !== (int) $document->id) {
                    throw new DomainException('A referenced line does not belong to the original document.');
                }
            }

            $description = $original?->description ?? trim((string) ($line['description'] ?? ''));
            if ($description === '') throw new DomainException('Every credit note line needs a description.');

            $quantityMilli = $this->scaledInteger((string) $line['quantity'], 3);
            if ($quantityMilli <= 0) throw new DomainException('Credit note quantities must be greater than zero.');

            if ($original) {
                $alreadyCredited = $credited[$original->id] ?? 0;
                $remainingMilli = $this->scaledInteger((string) $original->quantity, 3) - $alreadyCredited;
                if ($quantityMilli > $remainingMilli) {
                    throw new DomainException("The credited quantity for {$original->description} exceeds the uncredited quantity.");
                }
            }

            $unitPriceCents = $this->scaledInteger((string) ($original?->unit_price ?? $line['unit_price']), 2);
            if ($original && isset($line['unit_price']) && $line['unit_price'] !== '' && $line['unit_price'] !== null
                && $this->scaledInteger((string) $line['unit_price'], 2) !== $unitPriceCents) {
                throw new DomainException('A referenced line must use the original document unit price.');
            }

            $gross = $this->roundDivide($unitPriceCents * $quantityMilli, 1000);
            $lineDiscount = $this->scaledInteger((string) ($line['discount_amount'] ?? '0'), 2);
            if ($lineDiscount < 0 || $lineDiscount > $gross) throw new DomainException('Line discounts must be non-negative and cannot exceed the line amount.');
            $lineSubtotal = $gross - $lineDiscount;

            $taxInput = $line['tax_rate'] ?? null;
            if ($taxInput === null || $taxInput === '') $taxInput = $original?->tax_rate;
            $taxScaled = $taxInput === null || $taxInput === '' ? null : $this->scaledInteger((string) $taxInput, 4);
            if ($taxScaled !== null && ($taxScaled < 0 || $taxScaled > 1000000)) throw new DomainException('Tax rate must be between 0 and 100 percent.');
            $tax = $taxScaled === null ? 0 : $this->roundDivide($lineSubtotal * $taxScaled, 1000000);

            $subtotal += $lineSubtotal;
            $taxTotal += $tax;
            if ($original) $credited[$original->id] = ($credited[$original->id] ?? 0) + $quantityMilli;

            $snapshots[] = [
                'commercial_document_line_id' => $original?->id,
                'description' => $description,
                'detail' => $original?->detail ?? ($line['detail'] ?? null),
                'unit_of_measure' => $original?->unit_of_measure ?? ($line['unit_of_measure'] ?? 'item'),
                'quantity' => number_format($quantityMilli / 1000, 3, '.', ''),
                'unit_price' => $this->money($unitPriceCents),
                'discount_amount' => $this->money($lineDiscount),
                'line_subtotal' => $this->money($lineSubtotal),
                'tax_rate' => $taxScaled === null ? null : number_format($taxScaled / 10000, 4, '.', ''),
                'tax_amount' => $this->money($tax),
                'line_total' => $this->money($lineSubtotal + $tax),
                'position' => $position,
            ];
        }

        $discount = $this->scaledInteger((string) ($input['discount_amount'] ?? '0'), 2);
        if ($discount < 0 || $discount > $subtotal) throw new DomainException('Document discount must be non-negative and cannot exceed the subtotal.');

        $note->lines()->delete();
        foreach ($snapshots as $snapshot) $note->lines()->create($snapshot);
        $note->subtotal = $this->money($subtotal);
        $note->discount_amount = $this->money($discount);
        $note->discount_description = $discount ? ($input['discount_description'] ?? null) : null;
        $note->tax_amount = $this->money($taxTotal);
        $note->total = $this->money($subtotal - $discount + $taxTotal);

        $remaining = $this->remainingCreditableMinor($document, $note->exists ? $note->id : null);
        if (PaymentAllocationService::toMinorUnits((string) $note->total) > $remaining) {
            throw new DomainException('The credit note exceeds the remaining creditable amount of '.PaymentAllocationService::formatMinorUnits($remaining).'.');
        }
    }

    /** @return array<int, int> credited quantity in thousandths keyed by original line id */
    private function creditedQuantitiesForDocument(int $documentId, ?int $excludeNoteId = null): array
    {
        $query = CreditNoteLine::query()
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->where('credit_notes.commercial_document_id', $documentId)
            ->whereIn('credit_notes.status', CreditNote::EFFECTIVE_STATUSES)
            ->whereNotNull('credit_note_lines.commercial_document_line_id');
        if ($excludeNoteId !== null) $query->where('credit_notes.id', '!=', $excludeNoteId);

        $credited = [];
        foreach ($query->get(['credit_note_lines.commercial_document_line_id', 'credit_note_lines.quantity']) as $row) {
            $lineId = (int) $row->commercial_document_line_id;
            $credited[$lineId] = ($credited[$lineId] ?? 0) + $this->scaledInteger((string) $row->quantity, 3);
        }
        return $credited;
    }

    private function appliedMinor(CreditNote $note): int
    {
        return PaymentAllocationService::toMinorUnits((string) (CreditNoteApplication::query()
            ->where('credit_note_id', $note->id)->sum('amount') ?: '0'));
    }

    private function documentRow(CommercialDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_number' => $document->document_number,
            'document_type' => $document->document_type,
            'status' => $document->status,
            'issue_date' => $document->issue_date?->toDateString(),
            'currency' => $document->currency,
            'total' => $document->total,
            'payer' => $document->organisation
                ? ['type' => 'organisation', 'id' => $document->organisation->id, 'name' => $document->organisation->name]
                : ['type' => 'customer', 'id' => $document->customer?->id, 'name' => $document->customer?->name],
            'remaining_creditable' => PaymentAllocationService::formatMinorUnits($this->remainingCreditableMinor($document)),
            'payment_summary' => $this->allocations->balance($document),
        ];
    }

    private function rowView(CreditNote $note): array
    {
        $applied = $this->appliedMinor($note);
        $total = PaymentAllocationService::toMinorUnits((string) $note->total);
        return [
            'id' => $note->id,
            'credit_note_number' => $note->credit_note_number,
            'status' => $note->status,
            'currency' => $note->currency,
            'total' => $note->total,
            'applied_amount' => PaymentAllocationService::formatMinorUnits($applied),
            'remaining_amount' => PaymentAllocationService::formatMinorUnits(max(0, $total - $applied)),
            'payer' => $note->organisation
                ? ['type' => 'organisation', 'id' => $note->organisation->id, 'name' => $note->organisation->name]
                : ($note->customer ? ['type' => 'customer', 'id' => $note->customer->id, 'name' => $note->customer->name] : null),
            'original_document' => $note->commercialDocument ? [
                'id' => $note->commercialDocument->id,
                'document_number' => $note->commercialDocument->document_number,
            ] : null,
            'original_document_number' => $note->original_document_number,
            'created_by' => $note->created_by_name_snapshot ?: $note->creator?->name,
            'approved_by' => $note->approved_by_name_snapshot ?: $note->approver?->name,
            'issued_by' => $note->issued_by_name_snapshot ?: $note->issuer?->name,
            'issue_date' => $note->issue_date?->toDateString(),
            'created_at' => $note->created_at,
        ];
    }

    private function record(string $action, CreditNote $note, ?User $actor, string $description, ?array $old = null, ?array $new = null, ?Request $request = null): void
    {
        $this->audit->log($actor, $action, $description, $note, $old, $new, $request);
    }

    private function scaledInteger(string $value, int $scale): int
    {
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,'.$scale.'}))?$/', $value, $matches)) throw new DomainException('Amounts and quantities must be valid non-negative decimal values.');
        $fraction = str_pad($matches[2] ?? '', $scale, '0');
        return ((int) $matches[1] * (10 ** $scale)) + (int) ($fraction === '' ? 0 : $fraction);
    }

    private function roundDivide(int $numerator, int $denominator): int { return intdiv($numerator + intdiv($denominator, 2), $denominator); }
    private function money(int $cents): string { return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT); }
}
