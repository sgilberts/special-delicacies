<?php

namespace App\Services;

use App\Models\CommercialDocument;
use App\Models\CommercialDocumentHistory;
use App\Models\Customer;
use App\Models\CustomerCreditLedgerEntry;
use App\Models\Organisation;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    public function __construct(
        private readonly PaymentAllocationService $allocations,
        private readonly CustomerCreditService $credit,
        private readonly CustomerCreditReversalService $reversals,
        private readonly Stage4cPolicy $policy,
        private readonly AuditService $audit,
    ) {}

    public function eligibleInvoices(array $filters = []): LengthAwarePaginator
    {
        $query = CommercialDocument::query()
            ->with(['customer:id,name', 'organisation:id,name'])
            ->where('document_type', CommercialDocument::TYPE_INVOICE)
            ->where('status', CommercialDocument::STATUS_ISSUED)
            ->whereNotNull('issued_at')->whereNull('voided_at')->where('total', '>', 0)
            ->whereRaw("commercial_documents.total > COALESCE((SELECT SUM(cn.total) FROM commercial_documents cn WHERE cn.related_document_id = commercial_documents.id AND cn.document_type = 'credit_note' AND cn.status = 'issued' AND cn.voided_at IS NULL), 0)")
            ->orderByDesc('issue_date')->orderByDesc('id');

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 30), 100)))
            ->through(function (CommercialDocument $invoice): array {
                return [
                    'id' => $invoice->id,
                    'document_number' => $invoice->document_number,
                    'payer' => $this->payerArray($invoice),
                    'currency' => $invoice->currency,
                    'total' => $invoice->total,
                    'remaining_creditable' => PaymentAllocationService::formatMinorUnits($this->remainingCreditableMinor($invoice)),
                    'issue_date' => $invoice->issue_date?->toDateString(),
                ];
            });
    }

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = CommercialDocument::query()
            ->with(['customer:id,name', 'organisation:id,name', 'relatedDocument:id,document_number'])
            ->where('document_type', CommercialDocument::TYPE_CREDIT_NOTE)
            ->latest('id');
        if (filled($filters['status'] ?? null)) $query->where('status', $filters['status']);

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (CommercialDocument $note) => $this->summary($note));
    }

    public function show(int $id): array
    {
        $note = CommercialDocument::query()
            ->with(['lines', 'histories', 'customer:id,name,email,phone', 'organisation:id,name,reference', 'relatedDocument:id,document_number,total,status', 'issuer:id,name', 'creator:id,name'])
            ->where('document_type', CommercialDocument::TYPE_CREDIT_NOTE)
            ->findOrFail($id);

        $creditEntry = $this->creditEntryFor($note);
        $applied = PaymentAllocationService::toMinorUnits((string) (DB::table('customer_credit_ledger_entries')
            ->where('commercial_document_id', $note->related_document_id)
            ->where('entry_type', CustomerCreditLedgerEntry::TYPE_APPLICATION)
            ->where('operation_reference', 'like', 'cn:'.$note->document_number.':%')
            ->sum('amount') ?: '0'));

        return $this->summary($note) + [
            'lines' => $note->lines->map(fn ($line) => [
                'description' => $line->description,
                'detail' => $line->detail,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'tax_rate' => $line->tax_rate,
                'tax_amount' => $line->tax_amount,
                'line_total' => $line->line_total,
            ])->values(),
            'histories' => $note->histories->map(fn ($history) => [
                'action' => $history->action,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'actor' => $history->user_id ? optional(User::find($history->user_id))->name : null,
                'metadata' => $history->metadata,
                'created_at' => $history->created_at,
            ])->values(),
            'credit_entry' => $creditEntry ? [
                'id' => $creditEntry->id,
                'reference' => $creditEntry->operation_reference,
                'amount' => $creditEntry->amount,
                'remaining_reversible' => PaymentAllocationService::formatMinorUnits($this->reversals->remainingReversibleMinor($creditEntry)),
            ] : null,
            'applied_to_invoice' => PaymentAllocationService::formatMinorUnits($applied),
        ];
    }

    public function create(User $actor, array $input, ?Request $request = null): CommercialDocument
    {
        return DB::transaction(function () use ($actor, $input, $request): CommercialDocument {
            $invoice = CommercialDocument::query()->findOrFail((int) $input['originating_document_id']);
            $this->assertEligibleInvoice($invoice);

            $lines = $this->normaliseLines($input, $invoice);
            $totals = $this->totalsFromLines($lines, (string) ($input['discount_amount'] ?? '0'));
            if ($totals['total'] <= 0) throw new DomainException('A credit note total must be greater than zero.');

            $remaining = $this->remainingCreditableMinor($invoice);
            if ($totals['total'] > $remaining) throw new DomainException('The credit note exceeds the invoice’s remaining creditable amount.');
            if ($totals['total'] < $remaining && ! $this->policy->partialCreditNotesAllowed()) {
                throw new DomainException('Partial credit notes are not enabled by the current financial policy.');
            }

            $note = CommercialDocument::query()->create([
                'document_number' => null,
                'document_type' => CommercialDocument::TYPE_CREDIT_NOTE,
                'related_document_id' => $invoice->id,
                'status' => CommercialDocument::STATUS_DRAFT,
                'customer_id' => $invoice->customer_id,
                'organisation_id' => $invoice->organisation_id,
                'issue_date' => null,
                'currency' => $invoice->currency,
                'subtotal' => $this->money($totals['subtotal']),
                'discount_amount' => $this->money($totals['discount']),
                'tax_amount' => $this->money($totals['tax']),
                'total' => $this->money($totals['total']),
                'notes' => $input['notes'] ?? null,
                'terms' => $input['terms'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $note->document_number = sprintf('CN-%s-%08d', now()->format('Y'), $note->id);
            $note->save();
            foreach ($totals['snapshots'] as $snapshot) $note->lines()->create($snapshot);

            $this->record($note, $actor, 'created', null, CommercialDocument::STATUS_DRAFT, $request, [
                'originating_document_id' => $invoice->id,
                'originating_document_number' => $invoice->document_number,
            ]);

            return $note->fresh(['lines', 'relatedDocument', 'customer', 'organisation']);
        }, 3);
    }

    public function issue(User $actor, CommercialDocument $document, ?Request $request = null): CommercialDocument
    {
        $preloaded = CommercialDocument::query()->with('relatedDocument')->findOrFail($document->id);
        if ($preloaded->document_type !== CommercialDocument::TYPE_CREDIT_NOTE) throw new DomainException('Only credit notes can be issued through this workflow.');
        if (! $preloaded->relatedDocument) throw new DomainException('The credit note has no originating invoice.');
        $payer = $this->payerFor($preloaded->relatedDocument);

        return DB::transaction(function () use ($actor, $preloaded, $payer, $request): CommercialDocument {
            $this->lockPayer($payer);
            $invoice = CommercialDocument::query()->lockForUpdate()->findOrFail($preloaded->related_document_id);
            $note = CommercialDocument::query()->lockForUpdate()->with('lines')->findOrFail($preloaded->id);

            if ($note->status === CommercialDocument::STATUS_ISSUED) return $note->fresh(['lines', 'relatedDocument']);
            if ($note->status !== CommercialDocument::STATUS_DRAFT) throw new DomainException('Only draft credit notes can be issued.');
            if ($this->policy->creditNoteSeparationRequired() && (int) $note->created_by === (int) $actor->id) {
                throw new DomainException('A different staff member must issue this credit note.');
            }
            $this->assertEligibleInvoice($invoice);

            $total = PaymentAllocationService::toMinorUnits((string) $note->total);
            $remaining = $this->remainingCreditableMinor($invoice);
            if ($total > $remaining) throw new DomainException('The credit note exceeds the invoice’s remaining creditable amount.');

            $balanceBefore = $this->credit->currentBalanceMinor($payer, $note->currency, true);
            $creditEntry = CustomerCreditLedgerEntry::query()->create([
                'entry_type' => CustomerCreditLedgerEntry::TYPE_CREDIT_NOTE_CREDIT,
                'operation_reference' => 'cn:'.$note->document_number.':credit',
                'source_payment_transaction_id' => null,
                'commercial_document_id' => $note->id,
                'reverses_entry_id' => null,
                'customer_id' => $note->customer_id,
                'organisation_id' => $note->organisation_id,
                'actor_user_id' => $actor->id,
                'actor_user_id_snapshot' => $actor->id,
                'actor_name_snapshot' => $actor->name,
                'actor_reference_snapshot' => 'user:'.$actor->id,
                'amount' => PaymentAllocationService::formatMinorUnits($total),
                'currency' => $note->currency,
                'reason' => 'Credit note '.$note->document_number.' issued against '.$invoice->document_number,
                'credit_balance_before' => PaymentAllocationService::formatMinorUnits($balanceBefore),
                'credit_balance_after' => PaymentAllocationService::formatMinorUnits($balanceBefore + $total),
                'created_at' => now(),
            ]);

            $applied = 0;
            if ($this->policy->creditNoteAutoApply()) {
                $outstanding = $this->allocations->invoiceOutstandingMinor($invoice);
                $apply = min($outstanding, $total);
                if ($apply > 0) {
                    CustomerCreditLedgerEntry::query()->create([
                        'entry_type' => CustomerCreditLedgerEntry::TYPE_APPLICATION,
                        'operation_reference' => 'cn:'.$note->document_number.':apply',
                        'source_payment_transaction_id' => null,
                        'commercial_document_id' => $invoice->id,
                        'reverses_entry_id' => null,
                        'customer_id' => $note->customer_id,
                        'organisation_id' => $note->organisation_id,
                        'actor_user_id' => $actor->id,
                        'actor_user_id_snapshot' => $actor->id,
                        'actor_name_snapshot' => $actor->name,
                        'actor_reference_snapshot' => 'user:'.$actor->id,
                        'amount' => PaymentAllocationService::formatMinorUnits($apply),
                        'currency' => $note->currency,
                        'reason' => 'Apply credit note '.$note->document_number.' to '.$invoice->document_number,
                        'credit_balance_before' => PaymentAllocationService::formatMinorUnits($balanceBefore + $total),
                        'credit_balance_after' => PaymentAllocationService::formatMinorUnits($balanceBefore + $total - $apply),
                        'invoice_outstanding_before' => PaymentAllocationService::formatMinorUnits($outstanding),
                        'invoice_outstanding_after' => PaymentAllocationService::formatMinorUnits($outstanding - $apply),
                        'created_at' => now(),
                    ]);
                    $applied = $apply;
                }
            }

            $note->forceFill([
                'status' => CommercialDocument::STATUS_ISSUED,
                'issue_date' => now()->toDateString(),
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->record($note, $actor, 'issued', CommercialDocument::STATUS_DRAFT, CommercialDocument::STATUS_ISSUED, $request, [
                'credit_entry_id' => $creditEntry->id,
                'applied_to_invoice' => PaymentAllocationService::formatMinorUnits($applied),
            ]);
            $this->audit->log($actor, 'stage4c.credit_note.issued', 'Issued credit note '.$note->document_number.'.', $note, null, [
                'total' => $note->total,
                'currency' => $note->currency,
                'originating_document_id' => $invoice->id,
                'applied_to_invoice' => PaymentAllocationService::formatMinorUnits($applied),
            ]);

            return $note->fresh(['lines', 'relatedDocument', 'customer', 'organisation']);
        }, 3);
    }

    public function void(User $actor, CommercialDocument $document, string $reason, ?Request $request = null): CommercialDocument
    {
        if (trim($reason) === '') throw new DomainException('A void reason is required.');
        $preloaded = CommercialDocument::query()->with('relatedDocument')->findOrFail($document->id);
        if ($preloaded->document_type !== CommercialDocument::TYPE_CREDIT_NOTE) throw new DomainException('Only credit notes can be voided.');
        if (! $preloaded->relatedDocument) throw new DomainException('The credit note has no originating invoice.');
        $payer = $this->payerFor($preloaded->relatedDocument);

        return DB::transaction(function () use ($actor, $preloaded, $payer, $reason, $request): CommercialDocument {
            $this->lockPayer($payer);
            $note = CommercialDocument::query()->lockForUpdate()->findOrFail($preloaded->id);
            if ($note->status === CommercialDocument::STATUS_VOIDED) return $note;
            if ($note->status !== CommercialDocument::STATUS_ISSUED) throw new DomainException('Only issued credit notes can be voided.');
            if ($this->policy->creditNoteSeparationRequired() && (int) $note->created_by === (int) $actor->id) {
                throw new DomainException('A different staff member must void this credit note.');
            }

            $creditEntry = $this->creditEntryFor($note);
            if (! $creditEntry) throw new DomainException('The credit note has no credit ledger entry.');
            $remaining = $this->reversals->remainingReversibleMinor($creditEntry);
            if ($remaining < PaymentAllocationService::toMinorUnits((string) $creditEntry->amount)) {
                throw new DomainException('The credit note credit has been used; reverse the applied credit before voiding it.');
            }

            $balanceBefore = $this->credit->currentBalanceMinor($payer, $note->currency, true);
            $total = PaymentAllocationService::toMinorUnits((string) $note->total);
            CustomerCreditLedgerEntry::query()->create([
                'entry_type' => CustomerCreditLedgerEntry::TYPE_REVERSAL,
                'operation_reference' => 'cn:'.$note->document_number.':void',
                'source_payment_transaction_id' => null,
                'commercial_document_id' => null,
                'reverses_entry_id' => $creditEntry->id,
                'customer_id' => $note->customer_id,
                'organisation_id' => $note->organisation_id,
                'actor_user_id' => $actor->id,
                'actor_user_id_snapshot' => $actor->id,
                'actor_name_snapshot' => $actor->name,
                'actor_reference_snapshot' => 'user:'.$actor->id,
                'authorized_by_user_id' => $actor->id,
                'authorized_by_name_snapshot' => $actor->name,
                'authorized_at' => now(),
                'amount' => PaymentAllocationService::formatMinorUnits($total),
                'currency' => $note->currency,
                'reason' => 'Void credit note '.$note->document_number.': '.trim($reason),
                'credit_balance_before' => PaymentAllocationService::formatMinorUnits($balanceBefore),
                'credit_balance_after' => PaymentAllocationService::formatMinorUnits($balanceBefore - $total),
                'created_at' => now(),
            ]);

            $note->forceFill([
                'status' => CommercialDocument::STATUS_VOIDED,
                'voided_at' => now(),
                'void_reason' => trim($reason),
                'updated_by' => $actor->id,
            ])->save();
            $this->record($note, $actor, 'voided', CommercialDocument::STATUS_ISSUED, CommercialDocument::STATUS_VOIDED, $request, ['reason' => trim($reason)]);
            $this->audit->log($actor, 'stage4c.credit_note.voided', 'Voided credit note '.$note->document_number.'.', $note, null, ['reason' => trim($reason)]);

            return $note->fresh(['lines', 'relatedDocument', 'customer', 'organisation']);
        }, 3);
    }

    public function remainingCreditableMinor(CommercialDocument $invoice): int
    {
        $issued = DB::table('commercial_documents')
            ->where('related_document_id', $invoice->id)
            ->where('document_type', CommercialDocument::TYPE_CREDIT_NOTE)
            ->where('status', CommercialDocument::STATUS_ISSUED)
            ->whereNull('voided_at')
            ->sum('total');

        return max(0, PaymentAllocationService::toMinorUnits((string) $invoice->total) - PaymentAllocationService::toMinorUnits((string) ($issued ?: '0')));
    }

    public function creditEntryFor(CommercialDocument $note): ?CustomerCreditLedgerEntry
    {
        return CustomerCreditLedgerEntry::query()
            ->where('commercial_document_id', $note->id)
            ->where('entry_type', CustomerCreditLedgerEntry::TYPE_CREDIT_NOTE_CREDIT)
            ->first();
    }

    private function summary(CommercialDocument $note): array
    {
        return [
            'id' => $note->id,
            'document_number' => $note->document_number,
            'document_type' => $note->document_type,
            'status' => $note->status,
            'payer' => $this->payerArray($note),
            'currency' => $note->currency,
            'subtotal' => $note->subtotal,
            'discount_amount' => $note->discount_amount,
            'tax_amount' => $note->tax_amount,
            'total' => $note->total,
            'notes' => $note->notes,
            'originating_document' => $note->relatedDocument ? [
                'id' => $note->relatedDocument->id,
                'document_number' => $note->relatedDocument->document_number,
                'total' => $note->relatedDocument->total,
                'status' => $note->relatedDocument->status,
            ] : null,
            'created_by' => $note->creator?->name,
            'issued_by' => $note->issuer?->name,
            'issued_at' => $note->issued_at,
            'voided_at' => $note->voided_at,
            'void_reason' => $note->void_reason,
            'created_at' => $note->created_at,
        ];
    }

    private function normaliseLines(array $input, CommercialDocument $invoice): array
    {
        if (is_array($input['lines'] ?? null) && $input['lines'] !== []) return $input['lines'];
        if (! filled($input['amount'] ?? null)) throw new DomainException('Provide credit note lines or an amount.');

        return [[
            'description' => $input['description'] ?? ('Credit against '.$invoice->document_number),
            'quantity' => '1',
            'unit_price' => (string) $input['amount'],
            'discount_amount' => '0',
            'tax_rate' => null,
        ]];
    }

    private function totalsFromLines(array $lines, string $documentDiscount): array
    {
        $snapshots = [];
        $subtotal = 0;
        $taxTotal = 0;
        foreach ($lines as $position => $line) {
            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') throw new DomainException('Every credit note line needs a description.');
            $quantityMilli = $this->scaledInteger((string) ($line['quantity'] ?? '1'), 3);
            if ($quantityMilli <= 0) throw new DomainException('Credit note quantities must be greater than zero.');
            $unitPriceCents = $this->scaledInteger((string) ($line['unit_price'] ?? ''), 2);
            $gross = $this->roundDivide($unitPriceCents * $quantityMilli, 1000);
            $lineDiscount = $this->scaledInteger((string) ($line['discount_amount'] ?? '0'), 2);
            if ($lineDiscount < 0 || $lineDiscount > $gross) throw new DomainException('Line discounts must be non-negative and cannot exceed the line amount.');
            $lineSubtotal = $gross - $lineDiscount;
            $taxInput = $line['tax_rate'] ?? null;
            $taxScaled = $taxInput === null || $taxInput === '' ? null : $this->scaledInteger((string) $taxInput, 4);
            if ($taxScaled !== null && ($taxScaled < 0 || $taxScaled > 1000000)) throw new DomainException('Tax rate must be between 0 and 100 percent.');
            $tax = $taxScaled === null ? 0 : $this->roundDivide($lineSubtotal * $taxScaled, 1000000);
            $subtotal += $lineSubtotal;
            $taxTotal += $tax;
            $snapshots[] = [
                'menu_item_id' => null,
                'description' => $description,
                'detail' => $line['detail'] ?? null,
                'unit_of_measure' => $line['unit_of_measure'] ?? 'item',
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
        $discount = $this->scaledInteger($documentDiscount === '' ? '0' : $documentDiscount, 2);
        if ($discount < 0 || $discount > $subtotal) throw new DomainException('Credit note discount must be non-negative and cannot exceed the subtotal.');

        return [
            'snapshots' => $snapshots,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $taxTotal,
            'total' => $subtotal - $discount + $taxTotal,
        ];
    }

    private function assertEligibleInvoice(CommercialDocument $invoice): void
    {
        if ($invoice->document_type !== CommercialDocument::TYPE_INVOICE) throw new DomainException('Only invoices can be credited.');
        if ($invoice->status !== CommercialDocument::STATUS_ISSUED || ! $invoice->issued_at || $invoice->voided_at) {
            throw new DomainException('Only issued, non-voided invoices can be credited.');
        }
        if ((float) $invoice->total <= 0) throw new DomainException('The invoice has no creditable value.');
        if (($invoice->customer_id !== null) === ($invoice->organisation_id !== null)) throw new DomainException('The invoice must have exactly one payer.');
    }

    private function payerFor(CommercialDocument $document): Customer|Organisation
    {
        if ($document->organisation_id !== null) return Organisation::query()->findOrFail($document->organisation_id);
        if ($document->customer_id !== null) return Customer::query()->findOrFail($document->customer_id);
        throw new DomainException('The document has no payer.');
    }

    private function payerArray(CommercialDocument $document): array
    {
        return $document->organisation_id !== null
            ? ['type' => 'organisation', 'id' => $document->organisation?->id, 'name' => $document->organisation?->name]
            : ['type' => 'customer', 'id' => $document->customer?->id, 'name' => $document->customer?->name];
    }

    private function lockPayer(Customer|Organisation $payer): void
    {
        $table = $payer instanceof Customer ? 'customers' : 'organisations';
        if (! DB::table($table)->where('id', $payer->id)->lockForUpdate()->first()) {
            throw new DomainException('The invoice payer no longer exists.');
        }
    }

    private function record(CommercialDocument $document, User $user, string $action, ?string $from, ?string $to, ?Request $request, ?array $metadata = null): void
    {
        CommercialDocumentHistory::create([
            'commercial_document_id' => $document->id,
            'user_id' => $user->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    private function scaledInteger(string $value, int $scale): int
    {
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,'.$scale.'}))?$/', $value, $matches)) {
            throw new DomainException('Amounts and quantities must be valid non-negative decimal values.');
        }
        $fraction = str_pad($matches[2] ?? '', $scale, '0');
        return ((int) $matches[1] * (10 ** $scale)) + (int) ($fraction === '' ? 0 : $fraction);
    }

    private function roundDivide(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
