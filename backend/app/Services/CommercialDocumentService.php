<?php

namespace App\Services;

use App\Models\CommercialDocument;
use App\Models\CommercialDocumentHistory;
use App\Models\CommercialDocumentLine;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommercialDocumentService
{
    public function __construct(private readonly OrganisationAuthorization $organisationAuthorization) {}

    public function create(User $actor, array $input, Request $request): CommercialDocument
    {
        return DB::transaction(function () use ($actor, $input, $request): CommercialDocument {
            $this->assertRecipient($input['customer_id'] ?? null, $input['organisation_id'] ?? null);
            $document = CommercialDocument::create([
                'document_type' => $input['document_type'],
                'status' => CommercialDocument::STATUS_DRAFT,
                'customer_id' => $input['customer_id'],
                'organisation_id' => $input['organisation_id'] ?? null,
                'expiry_date' => $input['expiry_date'] ?? null,
                'currency' => 'GHS',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'notes' => $input['notes'] ?? null,
                'terms' => $input['terms'] ?? null,
            ]);
            $document->document_number = $this->numberFor($document);
            $document->save();
            $this->replaceLinesAndTotals($document, $input['lines'], $input['discount_amount'] ?? '0.00', $input['discount_description'] ?? null);
            $document->save();
            $this->record($document, $actor, null, 'created', null, CommercialDocument::STATUS_DRAFT, $request, ['document_type' => $document->document_type]);
            return $document->fresh(['lines', 'customer', 'organisation']);
        });
    }

    public function updateDraft(User $actor, CommercialDocument $document, array $input, Request $request): CommercialDocument
    {
        return DB::transaction(function () use ($actor, $document, $input, $request): CommercialDocument {
            $locked = CommercialDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->status !== CommercialDocument::STATUS_DRAFT) throw new DomainException('Only draft documents can be edited.');
            $this->assertRecipient($input['customer_id'], $input['organisation_id'] ?? null);
            $locked->customer_id = $input['customer_id'];
            $locked->organisation_id = $input['organisation_id'] ?? null;
            $locked->expiry_date = $input['expiry_date'] ?? null;
            $locked->notes = $input['notes'] ?? null;
            $locked->terms = $input['terms'] ?? null;
            $locked->discount_description = $input['discount_description'] ?? null;
            $locked->updated_by = $actor->id;
            $this->replaceLinesAndTotals($locked, $input['lines'], $input['discount_amount'] ?? '0.00', $input['discount_description'] ?? null);
            $locked->save();
            $this->record($locked, $actor, null, 'updated', CommercialDocument::STATUS_DRAFT, CommercialDocument::STATUS_DRAFT, $request, ['fields' => ['customer_id', 'organisation_id', 'lines', 'notes', 'terms', 'discount_amount']]);
            return $locked->fresh(['lines', 'customer', 'organisation']);
        });
    }

    public function issue(User $actor, CommercialDocument $document, Request $request): CommercialDocument
    {
        return DB::transaction(function () use ($actor, $document, $request): CommercialDocument {
            $locked = CommercialDocument::query()->lockForUpdate()->with('lines')->findOrFail($document->id);
            if ($locked->status !== CommercialDocument::STATUS_DRAFT) throw new DomainException('Only draft documents can be issued.');
            if ($locked->lines->isEmpty()) throw new DomainException('A document must contain at least one line item.');
            if ($locked->document_type === CommercialDocument::TYPE_QUOTATION && $locked->expiry_date && $locked->expiry_date->lt(today())) {
                throw new DomainException('A quotation with a past expiry date cannot be issued.');
            }
            $old = $locked->status;
            $locked->status = CommercialDocument::STATUS_ISSUED;
            $locked->issue_date = now()->toDateString();
            $locked->issued_at = now();
            $locked->issued_by = $actor->id;
            $locked->updated_by = $actor->id;
            $locked->save();
            $this->record($locked, $actor, null, 'issued', $old, $locked->status, $request);
            return $locked->fresh(['lines', 'customer', 'organisation']);
        });
    }

    public function customerTransition(Customer $actor, CommercialDocument $document, string $action, ?string $reason, Request $request): CommercialDocument
    {
        return DB::transaction(function () use ($actor, $document, $action, $reason, $request): CommercialDocument {
            $locked = CommercialDocument::query()->lockForUpdate()->findOrFail($document->id);
            if (! in_array($action, ['accept', 'decline'], true)) throw new DomainException('Unsupported quotation action.');
            if ($locked->document_type !== CommercialDocument::TYPE_QUOTATION) throw new DomainException('Only quotations can be accepted or declined.');
            if (! app(CommercialDocumentAccess::class)->allows($actor, $locked, 'documents.accept')) abort(404);
            if ($locked->status === CommercialDocument::STATUS_ISSUED && $locked->expiry_date && $locked->expiry_date->lt(today())) {
                $locked->status = CommercialDocument::STATUS_EXPIRED;
                $locked->save();
                $this->record($locked, null, null, 'expired', CommercialDocument::STATUS_ISSUED, CommercialDocument::STATUS_EXPIRED, $request);
                return $locked->fresh(['lines', 'customer', 'organisation']);
            }
            if ($locked->status !== CommercialDocument::STATUS_ISSUED) throw new DomainException('Only issued quotations can be accepted or declined.');
            $target = $action === 'accept' ? CommercialDocument::STATUS_ACCEPTED : CommercialDocument::STATUS_DECLINED;
            $locked->status = $target;
            if ($target === CommercialDocument::STATUS_ACCEPTED) $locked->accepted_at = now();
            else $locked->declined_at = now();
            $locked->save();
            $this->record($locked, null, $actor, $action, CommercialDocument::STATUS_ISSUED, $target, $request, $reason ? ['reason' => $reason] : null);
            return $locked->fresh(['lines', 'customer', 'organisation']);
        });
    }

    public function staffTransition(User $actor, CommercialDocument $document, string $action, ?string $reason, Request $request): CommercialDocument
    {
        return DB::transaction(function () use ($actor, $document, $action, $reason, $request): CommercialDocument {
            $locked = CommercialDocument::query()->lockForUpdate()->findOrFail($document->id);
            $old = $locked->status;
            if ($action === 'cancel') {
                if ($locked->document_type !== CommercialDocument::TYPE_QUOTATION || ! in_array($old, [CommercialDocument::STATUS_DRAFT, CommercialDocument::STATUS_ISSUED], true)) {
                    throw new DomainException('Only draft or issued quotations can be cancelled.');
                }
                $locked->status = CommercialDocument::STATUS_CANCELLED;
                $locked->cancelled_at = now();
            } elseif ($action === 'void') {
                if ($locked->document_type !== CommercialDocument::TYPE_INVOICE || $old !== CommercialDocument::STATUS_ISSUED) {
                    throw new DomainException('Only issued invoices can be voided.');
                }
                $hasActiveAllocations = DB::table('payment_allocations as pa')
                    ->leftJoin('payment_allocation_reversals as pr', 'pr.payment_allocation_id', '=', 'pa.id')
                    ->where('pa.commercial_document_id', $locked->id)
                    ->whereNull('pr.id')
                    ->exists();
                if ($hasActiveAllocations) {
                    throw new DomainException('Reverse active payment allocations before voiding this invoice.');
                }
                $locked->status = CommercialDocument::STATUS_VOIDED;
                $locked->voided_at = now();
                $locked->void_reason = $reason;
            } else {
                throw new DomainException('Unsupported document action.');
            }
            $locked->updated_by = $actor->id;
            $locked->save();
            $this->record($locked, $actor, null, $action, $old, $locked->status, $request, $reason ? ['reason' => $reason] : null);
            return $locked->fresh(['lines', 'customer', 'organisation']);
        });
    }

    public function expireIfNeeded(CommercialDocument $document, ?Request $request = null): bool
    {
        if ($document->document_type !== CommercialDocument::TYPE_QUOTATION || $document->status !== CommercialDocument::STATUS_ISSUED
            || ! $document->expiry_date || ! $document->expiry_date->lt(today())) return false;

        return DB::transaction(function () use ($document, $request): bool {
            $locked = CommercialDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->document_type !== CommercialDocument::TYPE_QUOTATION || $locked->status !== CommercialDocument::STATUS_ISSUED
                || ! $locked->expiry_date || ! $locked->expiry_date->lt(today())) return false;
            $locked->status = CommercialDocument::STATUS_EXPIRED;
            $locked->save();
            $this->record($locked, null, null, 'expired', CommercialDocument::STATUS_ISSUED, CommercialDocument::STATUS_EXPIRED, $request);
            return true;
        });
    }

    private function assertRecipient(?int $customerId, ?int $organisationId): void
    {
        $customer = $customerId ? Customer::query()->whereKey($customerId)->where('is_active', true)->lockForUpdate()->first() : null;
        if (! $customer) throw new DomainException('Select an active customer recipient.');
        if ($organisationId) {
            $organisation = Organisation::query()->whereKey($organisationId)->where('status', Organisation::STATUS_ACTIVE)->lockForUpdate()->first();
            $membership = OrganisationMembership::query()->where('organisation_id', $organisationId)->where('customer_id', $customer->id)->where('status', OrganisationMembership::STATUS_ACTIVE)->lockForUpdate()->first();
            if (! $organisation || ! $membership) {
                throw new DomainException('The customer recipient must be an active member of the selected organisation.');
            }
        }
    }

    private function replaceLinesAndTotals(CommercialDocument $document, array $lines, string|int|float $documentDiscount, ?string $discountDescription): void
    {
        $snapshots = [];
        $subtotal = 0;
        $taxTotal = 0;
        foreach ($lines as $position => $line) {
            $menuItem = ! empty($line['menu_item_id']) ? MenuItem::query()->whereKey($line['menu_item_id'])->where('is_available', true)->first() : null;
            if (! empty($line['menu_item_id']) && ! $menuItem) throw new DomainException('A selected catalogue item is unavailable.');
            $description = $menuItem?->name ?? trim((string) ($line['description'] ?? ''));
            if ($description === '') throw new DomainException('Every line item needs a description.');
            $quantityMilli = $this->scaledInteger((string) $line['quantity'], 3);
            $unitPriceCents = $this->scaledInteger((string) ($menuItem?->price ?? $line['unit_price']), 2);
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
                'menu_item_id' => $menuItem?->id,
                'description' => $description,
                'detail' => $menuItem ? $menuItem->description : ($line['detail'] ?? null),
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
        $discount = $this->scaledInteger((string) $documentDiscount, 2);
        if ($discount < 0 || $discount > $subtotal) throw new DomainException('Document discount must be non-negative and cannot exceed the subtotal.');
        $document->lines()->delete();
        foreach ($snapshots as $snapshot) $document->lines()->create($snapshot);
        $document->subtotal = $this->money($subtotal);
        $document->discount_amount = $this->money($discount);
        $document->discount_description = $discount ? $discountDescription : null;
        $document->tax_amount = $this->money($taxTotal);
        $document->total = $this->money($subtotal - $discount + $taxTotal);
    }

    private function record(CommercialDocument $document, ?User $user, ?Customer $customer, string $action, ?string $from, ?string $to, ?Request $request, ?array $metadata = null): void
    {
        CommercialDocumentHistory::create([
            'commercial_document_id' => $document->id,
            'user_id' => $user?->id,
            'customer_id' => $customer?->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    private function numberFor(CommercialDocument $document): string
    {
        $prefix = $document->document_type === CommercialDocument::TYPE_QUOTATION ? 'QT' : 'INV';
        return sprintf('%s-%s-%08d', $prefix, now()->format('Y'), $document->id);
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
