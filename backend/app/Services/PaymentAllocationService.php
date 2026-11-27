<?php

namespace App\Services;

use App\Models\CommercialDocument;
use App\Models\PaymentAllocation;
use App\Models\PaymentAllocationOperation;
use App\Models\PaymentAllocationReversal;
use App\Models\PaymentTransaction;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PaymentAllocationService
{
    /** List only explicitly invoice-purpose, verified receipts; order receipts are never eligible. */
    public function eligiblePayments(array $filters = [])
    {
        $query = PaymentTransaction::query()
            ->with(['customer:id,name,email', 'organisation:id,name,reference'])
            ->withSum(['allocations as allocated_amount' => fn (Builder $allocations) => $allocations->whereDoesntHave('reversal')], 'amount')
            ->withSum('creditDesignations as credit_designated_amount', 'amount')
            ->where('payment_purpose', 'invoice')
            ->whereNull('order_id')
            ->where('status', 'succeeded')
            ->whereNotNull('verified_at')
            ->whereNotNull('received_at')
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        // Exactly one payer target is enforced here even on engines that do not
        // support CHECK constraints consistently across all environments.
        $query->where(function (Builder $payer): void {
            $payer->where(function (Builder $individual): void {
                $individual->whereNotNull('customer_id')->whereNull('organisation_id');
            })->orWhere(function (Builder $organisation): void {
                $organisation->whereNull('customer_id')->whereNotNull('organisation_id');
            });
        });
        $query->whereRaw("payment_transactions.amount > COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payment_allocation_operations po ON po.id = pa.payment_allocation_operation_id LEFT JOIN payment_allocation_reversals pr ON pr.payment_allocation_id = pa.id WHERE po.payment_transaction_id = payment_transactions.id AND pr.id IS NULL), 0) + COALESCE((SELECT SUM(ce.amount) FROM customer_credit_ledger_entries ce WHERE ce.source_payment_transaction_id = payment_transactions.id AND ce.entry_type = 'designation'), 0)");

        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $query->where(fn (Builder $search) => $search
                ->where('transaction_reference', 'like', $term)
                ->orWhere('provider_reference', 'like', $term)
                ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $term)->orWhere('email', 'like', $term))
                ->orWhereHas('organisation', fn (Builder $organisation) => $organisation->where('name', 'like', $term)->orWhere('reference', 'like', $term)));
        }

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 20), 100)))
            ->through(function (PaymentTransaction $payment): array {
                $amount = self::toMinorUnits((string) $payment->amount);
                $allocated = self::toMinorUnits((string) ($payment->allocated_amount ?? '0'));
                $credited = self::toMinorUnits((string) ($payment->credit_designated_amount ?? '0'));
                return [
                    'id' => $payment->id,
                    'transaction_reference' => $payment->transaction_reference,
                    'provider_reference' => $payment->provider_reference,
                    'provider' => $payment->provider,
                    'payment_method' => $payment->payment_method,
                    'amount' => self::formatMinorUnits($amount),
                    'allocated_amount' => self::formatMinorUnits($allocated),
                    'credit_designated_amount' => self::formatMinorUnits($credited),
                    'unallocated_amount' => self::formatMinorUnits(max(0, $amount - $allocated - $credited)),
                    'currency' => $payment->currency,
                    'verified_at' => $payment->verified_at,
                    'received_at' => $payment->received_at,
                    'payer' => $payment->organisation
                        ? ['type' => 'organisation', 'id' => $payment->organisation->id, 'name' => $payment->organisation->name]
                        : ['type' => 'customer', 'id' => $payment->customer->id, 'name' => $payment->customer->name],
                ];
            });
    }

    public function eligibleInvoices(int $paymentId)
    {
        $payment = PaymentTransaction::query()->findOrFail($paymentId);
        $this->assertInvoiceReceipt($payment);
        $query = CommercialDocument::query()
            ->with(['customer:id,name,email', 'organisation:id,name,reference'])
            ->where('document_type', CommercialDocument::TYPE_INVOICE)
            ->where('status', CommercialDocument::STATUS_ISSUED)
            ->whereNotNull('issued_at')
            ->where('currency', $payment->currency)
            ->where('total', '>', 0);
        if ($payment->organisation_id !== null) $query->where('organisation_id', $payment->organisation_id);
        else $query->whereNull('organisation_id')->where('customer_id', $payment->customer_id);
        $query->whereRaw("commercial_documents.total > COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa LEFT JOIN payment_allocation_reversals pr ON pr.payment_allocation_id = pa.id WHERE pa.commercial_document_id = commercial_documents.id AND pr.id IS NULL), 0) + COALESCE((SELECT SUM(ce.amount) FROM customer_credit_ledger_entries ce WHERE ce.commercial_document_id = commercial_documents.id AND ce.entry_type = 'application'), 0)");

        return $query->orderBy('issue_date')->orderBy('id')->paginate(50)->through(function (CommercialDocument $document): array {
            $summary = $this->summaryForDocument($document);
            return [
                'id' => $document->id,
                'document_number' => $document->document_number,
                'issue_date' => $document->issue_date?->toDateString(),
                'currency' => $document->currency,
                'total' => $summary['invoice_total'],
                'allocated_amount' => $summary['allocated_amount'],
                'credit_applied_amount' => $summary['credit_applied_amount'],
                'settled_amount' => $summary['settled_amount'],
                'outstanding_balance' => $summary['outstanding_balance'],
                'payment_state' => $summary['payment_state'],
                'customer' => $document->customer?->name,
                'organisation' => $document->organisation?->name,
            ];
        });
    }

    public function balance(CommercialDocument $document): array
    {
        $total = self::toMinorUnits((string) $document->total);
        $allocated = self::toMinorUnits((string) (DB::table('payment_allocations as pa')
            ->join('payment_allocation_operations as po', 'po.id', '=', 'pa.payment_allocation_operation_id')
            ->leftJoin('payment_allocation_reversals as pr', 'pr.payment_allocation_id', '=', 'pa.id')
            ->where('pa.commercial_document_id', $document->id)
            ->whereNull('pr.id')
            ->sum('pa.amount') ?: '0'));
        $creditApplied = self::toMinorUnits((string) (DB::table('customer_credit_ledger_entries')
            ->where('commercial_document_id', $document->id)
            ->where('entry_type', 'application')
            ->sum('amount') ?: '0'));
        $settled = $allocated + $creditApplied;
        $outstanding = max(0, $total - $settled);

        return [
            'document_id' => $document->id,
            'document_number' => $document->document_number,
            'currency' => $document->currency,
            'invoice_total' => self::formatMinorUnits($total),
            'allocated_amount' => self::formatMinorUnits($allocated),
            'credit_applied_amount' => self::formatMinorUnits($creditApplied),
            'settled_amount' => self::formatMinorUnits($settled),
            'outstanding_balance' => self::formatMinorUnits($outstanding),
            'payment_state' => $settled === 0 ? 'unpaid' : ($outstanding === 0 ? 'fully_paid' : 'partially_paid'),
        ];
    }

    /** Validate and return a current server-side preview without writing financial records. */
    public function preview(int $paymentId, array $lines): array
    {
        [$normalized, $payment, $documents] = $this->validatedAllocationContext($paymentId, $lines);
        $allocated = $this->allocatedForPayment($payment->id);
        $available = self::toMinorUnits((string) $payment->amount) - $allocated;
        $total = array_sum($normalized);
        if ($total > $available) throw new DomainException('The allocation exceeds the payment’s unallocated amount.');

        $proposed = [];
        foreach ($documents as $document) {
            $outstanding = $this->balanceMinor($document->id, (string) $document->total);
            $summary = $this->balance($document);
            if ($normalized[$document->id] > $outstanding) throw new DomainException("The allocation exceeds invoice {$document->document_number}'s outstanding balance.");
            $proposed[] = [
                'commercial_document_id' => $document->id,
                'document_number' => $document->document_number,
                'customer' => $document->customer?->name,
                'organisation' => $document->organisation?->name,
                'currency' => $document->currency,
                'invoice_total' => self::formatMinorUnits(self::toMinorUnits((string) $document->total)),
                'allocated_amount' => $summary['allocated_amount'],
                'credit_applied_amount' => $summary['credit_applied_amount'],
                'settled_amount' => $summary['settled_amount'],
                'proposed_amount' => self::formatMinorUnits($normalized[$document->id]),
                'resulting_balance' => self::formatMinorUnits($outstanding - $normalized[$document->id]),
            ];
        }

        return [
            'payment' => [
                'id' => $payment->id,
                'transaction_reference' => $payment->transaction_reference,
                'payer' => $payment->organisation?->name ?? $payment->customer?->name,
                'currency' => $payment->currency,
                'original_amount' => self::formatMinorUnits(self::toMinorUnits((string) $payment->amount)),
                'allocated_amount' => self::formatMinorUnits($allocated),
                'payment_allocated_amount' => self::formatMinorUnits($this->paymentAllocatedMinorForPayment($payment->id)),
                'credit_designated_amount' => self::formatMinorUnits($this->creditDesignatedMinorForPayment($payment->id)),
                'available_amount' => self::formatMinorUnits($available),
            ],
            'invoices' => $proposed,
            'proposed_total' => self::formatMinorUnits($total),
            'remaining_unallocated' => self::formatMinorUnits($available - $total),
        ];
    }

    public function summaryForDocument(CommercialDocument $document): array
    {
        $summary = $this->balance($document);
        $history = PaymentAllocation::query()
            ->with(['operation.paymentTransaction:id,transaction_reference,provider,payment_method,received_at', 'operation.actor:id,name', 'reversal.actor:id,name'])
            ->where('commercial_document_id', $document->id)
            ->orderBy('allocated_at')->orderBy('id')->get()
            ->map(fn (PaymentAllocation $allocation) => $this->allocationData($allocation))->values();
        return $summary + ['payments' => $history];
    }

    /** Current receipt amount not yet allocated to an invoice or designated as credit. */
    public function availableMinorForPayment(int $paymentId): int
    {
        $payment = PaymentTransaction::query()->findOrFail($paymentId);
        return max(0, self::toMinorUnits((string) $payment->amount) - $this->allocatedForPayment($paymentId));
    }

    public function paymentAllocatedMinorForPayment(int $paymentId): int
    {
        $amount = DB::table('payment_allocations as pa')
            ->join('payment_allocation_operations as po', 'po.id', '=', 'pa.payment_allocation_operation_id')
            ->leftJoin('payment_allocation_reversals as pr', 'pr.payment_allocation_id', '=', 'pa.id')
            ->where('po.payment_transaction_id', $paymentId)->whereNull('pr.id')->sum('pa.amount');
        return self::toMinorUnits((string) ($amount ?: '0'));
    }

    public function creditDesignatedMinorForPayment(int $paymentId): int
    {
        $designated = DB::table('customer_credit_ledger_entries')->where('source_payment_transaction_id', $paymentId)
            ->where('entry_type', 'designation')->sum('amount');

        // A credit reversal is a compensating entry: the reversed portion returns
        // to the receipt's uncommitted funds, so it no longer consumes the payment.
        $reversed = DB::table('customer_credit_ledger_entries as rev')
            ->join('customer_credit_ledger_entries as orig', 'orig.id', '=', 'rev.reverses_entry_id')
            ->where('orig.source_payment_transaction_id', $paymentId)
            ->where('orig.entry_type', 'designation')
            ->where('rev.entry_type', 'reversal')
            ->sum('rev.amount');

        return max(0, self::toMinorUnits((string) ($designated ?: '0')) - self::toMinorUnits((string) ($reversed ?: '0')));
    }

    public function invoiceOutstandingMinor(CommercialDocument $document): int
    {
        return max(0, $this->balanceMinor($document->id, (string) $document->total));
    }

    public function allocationsFor(CommercialDocument $document)
    {
        return PaymentAllocation::query()
            ->with(['operation.paymentTransaction:id,transaction_reference,provider,provider_reference,payment_method,status,verified_at,received_at', 'operation.actor:id,name', 'reversal.actor:id,name'])
            ->where('commercial_document_id', $document->id)
            ->orderByDesc('allocated_at')
            ->paginate(50)
            ->through(fn (PaymentAllocation $allocation) => $this->allocationData($allocation));
    }

    /** @param array<int, array{commercial_document_id:int,amount:string|int|float}> $lines */
    public function allocate(int $paymentId, array $lines, string $operationReference, User $actor, ?string $reason = null): array
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $operationReference)) {
            throw new DomainException('The allocation operation reference is invalid.');
        }
        if ($lines === [] || count($lines) > 50) throw new DomainException('Provide between one and fifty invoice allocations.');
        $normalized = [];
        foreach ($lines as $line) {
            $documentId = (int) ($line['commercial_document_id'] ?? 0);
            if ($documentId < 1 || isset($normalized[$documentId])) throw new DomainException('Invoice allocations must use distinct valid invoice IDs.');
            $normalized[$documentId] = self::toMinorUnits((string) ($line['amount'] ?? ''));
            if ($normalized[$documentId] <= 0) throw new DomainException('Allocation amounts must be greater than zero.');
        }
        ksort($normalized, SORT_NUMERIC);

        try {
            $operation = DB::transaction(function () use ($paymentId, $normalized, $operationReference, $actor, $reason): PaymentAllocationOperation {
                $payment = PaymentTransaction::query()->lockForUpdate()->find($paymentId);
                if (! $payment) throw new DomainException('Payment transaction not found.');
                $documents = CommercialDocument::query()->whereIn('id', array_keys($normalized))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($documents->count() !== count($normalized)) throw new DomainException('One or more invoices could not be found.');

                $existing = PaymentAllocationOperation::query()->with('allocations')->where('operation_reference', $operationReference)->first();
                if ($existing) {
                    if ((int) $existing->payment_transaction_id !== $paymentId || (int) $existing->allocated_by !== (int) $actor->id || ! $this->sameLines($existing, $normalized)) {
                        throw new DomainException('This operation reference was already used for a different allocation.');
                    }
                    return $existing;
                }

                $this->assertInvoiceReceipt($payment);
                $allocatedSoFar = $this->allocatedForPayment($payment->id);
                $paymentAvailable = self::toMinorUnits((string) $payment->amount) - $allocatedSoFar;
                $requestedTotal = array_sum($normalized);
                if ($requestedTotal > $paymentAvailable) throw new DomainException('The allocation exceeds the payment’s unallocated amount.');

                foreach ($documents as $document) {
                    $this->assertEligibleInvoice($payment, $document);
                    $balance = $this->balanceMinor($document->id, (string) $document->total);
                    if ($normalized[$document->id] > $balance) throw new DomainException("The allocation exceeds invoice {$document->document_number}'s outstanding balance.");
                }

                $now = now();
                $operation = PaymentAllocationOperation::create([
                    'payment_transaction_id' => $payment->id,
                    'operation_reference' => $operationReference,
                    'allocated_by' => $actor->id,
                    'actor_user_id_snapshot' => $actor->id,
                    'actor_name_snapshot' => $actor->name,
                    'actor_reference_snapshot' => 'user:'.$actor->id,
                    'payment_unallocated_before' => self::formatMinorUnits($paymentAvailable),
                    'payment_unallocated_after' => self::formatMinorUnits($paymentAvailable - $requestedTotal),
                    'reason' => $reason,
                    'allocated_at' => $now,
                ]);
                foreach ($documents as $document) {
                    $invoiceBalance = $this->balanceMinor($document->id, (string) $document->total);
                    PaymentAllocation::create([
                        'payment_allocation_operation_id' => $operation->id,
                        'commercial_document_id' => $document->id,
                        'amount' => self::formatMinorUnits($normalized[$document->id]),
                        'currency' => $payment->currency,
                        'invoice_balance_before' => self::formatMinorUnits($invoiceBalance),
                        'invoice_balance_after' => self::formatMinorUnits($invoiceBalance - $normalized[$document->id]),
                        'allocated_at' => $now,
                    ]);
                }
                return $operation->load(['allocations.commercialDocument', 'allocations.reversal']);
            }, 3);
        } catch (QueryException $exception) {
            $existing = PaymentAllocationOperation::query()->with('allocations')->where('operation_reference', $operationReference)->first();
            if (! $this->isOperationReferenceUniqueConflict($exception)) throw $exception;
            if ($existing && (int) $existing->payment_transaction_id === $paymentId && (int) $existing->allocated_by === (int) $actor->id && $this->sameLines($existing, $normalized)) {
                $operation = $existing;
            } elseif ($existing) {
                throw new DomainException('This operation reference was already used for a different allocation.');
            } else {
                throw $exception;
            }
        }

        return $this->operationData($operation, true);
    }

    public function reverse(PaymentAllocation $allocation, string $operationReference, string $reason, User $actor): array
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $operationReference)) throw new DomainException('The reversal operation reference is invalid.');
        if (trim($reason) === '') throw new DomainException('A reason is required to reverse an allocation.');

        $reversal = DB::transaction(function () use ($allocation, $operationReference, $reason, $actor): PaymentAllocationReversal {
            $preloaded = PaymentAllocation::query()->with('operation')->findOrFail($allocation->id);
            $payment = PaymentTransaction::query()->lockForUpdate()->findOrFail($preloaded->operation->payment_transaction_id);
            CommercialDocument::query()->whereKey($preloaded->commercial_document_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentAllocation::query()->with('operation')->lockForUpdate()->findOrFail($allocation->id);

            $existing = PaymentAllocationReversal::query()->where('operation_reference', $operationReference)->first();
            if ($existing) {
                if ((int) $existing->payment_allocation_id === (int) $locked->id && (int) $existing->reversed_by === (int) $actor->id) return $existing;
                throw new DomainException('This reversal reference was already used.');
            }
            if ($locked->reversal()->exists()) throw new DomainException('This allocation has already been reversed.');
            $invoice = CommercialDocument::query()->whereKey($locked->commercial_document_id)->firstOrFail();
            $balanceBefore = $this->balanceMinor($invoice->id, (string) $invoice->total);
            $paymentUnallocatedBefore = self::toMinorUnits((string) $payment->amount) - $this->allocatedForPayment($payment->id);
            $now = now();
            return PaymentAllocationReversal::create([
                'payment_allocation_id' => $locked->id,
                'operation_reference' => $operationReference,
                'reversed_by' => $actor->id,
                'actor_user_id_snapshot' => $actor->id,
                'actor_name_snapshot' => $actor->name,
                'actor_reference_snapshot' => 'user:'.$actor->id,
                'reason' => trim($reason),
                'payment_unallocated_before' => self::formatMinorUnits($paymentUnallocatedBefore),
                'payment_unallocated_after' => self::formatMinorUnits($paymentUnallocatedBefore + self::toMinorUnits((string) $locked->amount)),
                'invoice_balance_before' => self::formatMinorUnits($balanceBefore),
                'invoice_balance_after' => self::formatMinorUnits($balanceBefore + self::toMinorUnits((string) $locked->amount)),
                'reversed_at' => $now,
            ]);
        }, 3);

        return [
            'id' => $reversal->id,
            'allocation_id' => $reversal->payment_allocation_id,
            'operation_reference' => $reversal->operation_reference,
            'reason' => $reversal->reason,
            'payment_unallocated_before' => $reversal->payment_unallocated_before,
            'payment_unallocated_after' => $reversal->payment_unallocated_after,
            'invoice_balance_before' => $reversal->invoice_balance_before,
            'invoice_balance_after' => $reversal->invoice_balance_after,
            'reversed_by' => $actor->name,
            'reversed_at' => $reversal->reversed_at,
        ];
    }

    public static function toMinorUnits(string $amount): int
    {
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', trim($amount), $parts)) {
            throw new DomainException('Money values must be positive decimal amounts with at most two decimal places.');
        }
        return ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function formatMinorUnits(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    private function assertInvoiceReceipt(PaymentTransaction $payment): void
    {
        if ($payment->payment_purpose !== 'invoice' || $payment->order_id !== null) throw new DomainException('Only invoice-purpose receipts can be allocated.');
        if ($payment->status !== 'succeeded' || ! $payment->verified_at || ! $payment->received_at) throw new DomainException('Only verified and received successful payments can be allocated.');
        $onePayer = (($payment->customer_id !== null) xor ($payment->organisation_id !== null));
        if (! $onePayer) throw new DomainException('The payment must have exactly one identified payer.');
    }

    private function assertEligibleInvoice(PaymentTransaction $payment, CommercialDocument $document): void
    {
        if ($document->document_type !== CommercialDocument::TYPE_INVOICE) throw new DomainException('Quotations cannot receive payment allocations.');
        if ($document->status !== CommercialDocument::STATUS_ISSUED || ! $document->issued_at || $document->voided_at) throw new DomainException('Only issued, non-voided invoices can receive allocations.');
        if ($document->currency !== $payment->currency) throw new DomainException('Payment and invoice currencies do not match.');
        $samePayer = $payment->organisation_id !== null
            ? (int) $payment->organisation_id === (int) $document->organisation_id
            : $document->organisation_id === null && (int) $payment->customer_id === (int) $document->customer_id;
        if (! $samePayer) throw new DomainException('The payment payer does not match the invoice payer.');
    }

    private function allocatedForPayment(int $paymentId): int
    {
        return $this->paymentAllocatedMinorForPayment($paymentId) + $this->creditDesignatedMinorForPayment($paymentId);
    }

    private function balanceMinor(int $documentId, string $total): int
    {
        $allocated = DB::table('payment_allocations as pa')
            ->leftJoin('payment_allocation_reversals as pr', 'pr.payment_allocation_id', '=', 'pa.id')
            ->where('pa.commercial_document_id', $documentId)
            ->whereNull('pr.id')->sum('pa.amount');
        $creditApplied = DB::table('customer_credit_ledger_entries')
            ->where('commercial_document_id', $documentId)
            ->where('entry_type', 'application')->sum('amount');
        return self::toMinorUnits($total)
            - self::toMinorUnits((string) ($allocated ?: '0'))
            - self::toMinorUnits((string) ($creditApplied ?: '0'));
    }

    private function sameLines(PaymentAllocationOperation $operation, array $normalized): bool
    {
        $actual = $operation->allocations->mapWithKeys(fn (PaymentAllocation $line) => [
            (int) $line->commercial_document_id => self::toMinorUnits((string) $line->amount),
        ])->sortKeys()->all();
        return $actual === $normalized;
    }

    private function isOperationReferenceUniqueConflict(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'operation_reference')
            && ($driverCode === 1062 || $driverCode === 19 || str_contains($message, 'unique constraint'));
    }

    /** @return array{array<int,int>,PaymentTransaction,\Illuminate\Support\Collection<int,CommercialDocument>} */
    private function validatedAllocationContext(int $paymentId, array $lines): array
    {
        if ($lines === [] || count($lines) > 50) throw new DomainException('Provide between one and fifty invoice allocations.');
        $normalized = [];
        foreach ($lines as $line) {
            $documentId = (int) ($line['commercial_document_id'] ?? 0);
            if ($documentId < 1 || isset($normalized[$documentId])) throw new DomainException('Invoice allocations must use distinct valid invoice IDs.');
            $normalized[$documentId] = self::toMinorUnits((string) ($line['amount'] ?? ''));
            if ($normalized[$documentId] <= 0) throw new DomainException('Allocation amounts must be greater than zero.');
        }
        ksort($normalized, SORT_NUMERIC);
        $payment = PaymentTransaction::query()->with(['customer:id,name', 'organisation:id,name'])->findOrFail($paymentId);
        $this->assertInvoiceReceipt($payment);
        $documents = CommercialDocument::query()->with(['customer:id,name', 'organisation:id,name'])
            ->whereIn('id', array_keys($normalized))->orderBy('id')->get()->keyBy('id');
        if ($documents->count() !== count($normalized)) throw new DomainException('One or more invoices could not be found.');
        foreach ($documents as $document) {
            $this->assertEligibleInvoice($payment, $document);
        }
        return [$normalized, $payment, $documents];
    }

    private function operationData(PaymentAllocationOperation $operation, bool $includeHistory): array
    {
        $operation->loadMissing(['paymentTransaction:id,transaction_reference,amount,currency,provider,provider_reference', 'actor:id,name', 'allocations.commercialDocument', 'allocations.reversal.actor:id,name']);
        return [
            'id' => $operation->id,
            'operation_reference' => $operation->operation_reference,
            'payment' => [
                'id' => $operation->paymentTransaction->id,
                'transaction_reference' => $operation->paymentTransaction->transaction_reference,
                'provider_reference' => $operation->paymentTransaction->provider_reference,
                'provider' => $operation->paymentTransaction->provider,
                'amount' => $operation->paymentTransaction->amount,
                'currency' => $operation->paymentTransaction->currency,
            ],
            'allocated_by' => $operation->actor_name_snapshot ?: $operation->actor?->name,
            'reason' => $operation->reason,
            'payment_unallocated_before' => $operation->payment_unallocated_before,
            'payment_unallocated_after' => $operation->payment_unallocated_after,
            'allocated_at' => $operation->allocated_at,
            'allocations' => $operation->allocations->map(fn (PaymentAllocation $allocation) => $this->allocationData($allocation))->values(),
        ];
    }

    private function allocationData(PaymentAllocation $allocation): array
    {
        return [
            'id' => $allocation->id,
            'commercial_document_id' => $allocation->commercial_document_id,
            'document_number' => $allocation->commercialDocument?->document_number,
            'amount' => $allocation->amount,
            'currency' => $allocation->currency,
            'invoice_balance_before' => $allocation->invoice_balance_before,
            'invoice_balance_after' => $allocation->invoice_balance_after,
            'operation_reference' => $allocation->operation?->operation_reference,
            'transaction_reference' => $allocation->operation?->paymentTransaction?->transaction_reference,
            'received_at' => $allocation->operation?->paymentTransaction?->received_at,
            'allocated_by' => $allocation->operation?->actor_name_snapshot ?: $allocation->operation?->actor?->name,
            'allocated_at' => $allocation->allocated_at,
            'reversal' => $allocation->reversal ? [
                'operation_reference' => $allocation->reversal->operation_reference,
                'reason' => $allocation->reversal->reason,
                'payment_unallocated_before' => $allocation->reversal->payment_unallocated_before,
                'payment_unallocated_after' => $allocation->reversal->payment_unallocated_after,
                'reversed_by' => $allocation->reversal->actor_name_snapshot ?: $allocation->reversal->actor?->name,
                'reversed_at' => $allocation->reversal->reversed_at,
            ] : null,
        ];
    }
}
