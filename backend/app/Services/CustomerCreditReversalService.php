<?php

namespace App\Services;

use App\Models\CreditReversalRequest;
use App\Models\Customer;
use App\Models\CustomerCreditLedgerEntry;
use App\Models\Organisation;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerCreditReversalService
{
    public function __construct(
        private readonly CustomerCreditService $credit,
        private readonly Stage4cPolicy $policy,
        private readonly AuditService $audit,
    ) {}

    /**
     * Credit entries (designations and credit-note credit) that still have an
     * unconsumed, unreversed balance available to reverse.
     */
    public function eligibleEntries(array $filters = []): LengthAwarePaginator
    {
        $query = CustomerCreditLedgerEntry::query()
            ->with(['customer:id,name,email,phone', 'organisation:id,name,reference', 'sourcePayment:id,transaction_reference', 'commercialDocument:id,document_number'])
            ->whereIn('entry_type', CustomerCreditLedgerEntry::CREDIT_TYPES)
            ->latest('created_at')->latest('id');
        if (isset($filters['customer_id'])) $query->where('customer_id', $filters['customer_id']);
        if (isset($filters['organisation_id'])) $query->where('organisation_id', $filters['organisation_id']);
        if (filled($filters['currency'] ?? null)) $query->where('currency', $filters['currency']);

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(function (CustomerCreditLedgerEntry $entry): array {
                $remaining = $this->remainingReversibleMinor($entry);
                return $this->entrySummary($entry) + [
                    'remaining_reversible' => PaymentAllocationService::formatMinorUnits($remaining),
                    'eligible' => $remaining > 0,
                ];
            });
    }

    public function preview(int $ledgerEntryId): array
    {
        $entry = CustomerCreditLedgerEntry::query()->with(['customer', 'organisation', 'sourcePayment', 'commercialDocument'])->findOrFail($ledgerEntryId);
        if (! in_array($entry->entry_type, CustomerCreditLedgerEntry::CREDIT_TYPES, true)) {
            throw new DomainException('Only customer credit entries can be reversed.');
        }

        return $this->entrySummary($entry) + [
            'remaining_reversible' => PaymentAllocationService::formatMinorUnits($this->remainingReversibleMinor($entry)),
            'separation_required' => $this->policy->reversalSeparationRequired(),
        ];
    }

    public function requests(array $filters = []): LengthAwarePaginator
    {
        $query = CreditReversalRequest::query()
            ->with(['ledgerEntry:id,operation_reference,entry_type,amount,currency,created_at', 'customer:id,name', 'organisation:id,name', 'requester:id,name', 'approver:id,name'])
            ->latest('requested_at')->latest('id');
        if (filled($filters['status'] ?? null)) $query->where('status', $filters['status']);
        if (isset($filters['customer_id'])) $query->where('customer_id', $filters['customer_id']);
        if (isset($filters['organisation_id'])) $query->where('organisation_id', $filters['organisation_id']);

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (CreditReversalRequest $request) => $this->requestView($request));
    }

    public function initiate(int $ledgerEntryId, string $amount, string $operationReference, string $reason, User $actor): CreditReversalRequest
    {
        $minor = PaymentAllocationService::toMinorUnits($amount);
        if ($minor <= 0) throw new DomainException('A reversal amount must be greater than zero.');
        $this->assertReference($operationReference);
        if (trim($reason) === '') throw new DomainException('A reason is required to reverse customer credit.');

        $snapshot = CustomerCreditLedgerEntry::query()->find($ledgerEntryId);
        if (! $snapshot) throw new DomainException('Credit ledger entry not found.');
        if (! in_array($snapshot->entry_type, CustomerCreditLedgerEntry::CREDIT_TYPES, true)) {
            throw new DomainException('Only customer credit entries can be reversed.');
        }
        $payer = $this->payerFor($snapshot);

        return DB::transaction(function () use ($ledgerEntryId, $minor, $operationReference, $reason, $actor, $payer): CreditReversalRequest {
            $this->lockPayer($payer);
            $entry = CustomerCreditLedgerEntry::query()->lockForUpdate()->findOrFail($ledgerEntryId);

            $existing = CreditReversalRequest::query()->where('operation_reference', $operationReference)->first();
            if ($existing) {
                $this->assertSameOperation($existing, $entry, $minor, $reason, $actor);
                return $existing;
            }

            $remaining = $this->remainingReversibleMinor($entry);
            if ($minor > $remaining) {
                throw new DomainException('The reversal exceeds the entry’s unconsumed, unreversed credit.');
            }

            $request = CreditReversalRequest::query()->create([
                'operation_reference' => $operationReference,
                'ledger_entry_id' => $entry->id,
                'customer_id' => $entry->customer_id,
                'organisation_id' => $entry->organisation_id,
                'amount' => PaymentAllocationService::formatMinorUnits($minor),
                'currency' => $entry->currency,
                'reason' => trim($reason),
                'status' => CreditReversalRequest::STATUS_REQUESTED,
                'requested_by' => $actor->id,
                'requested_by_id_snapshot' => $actor->id,
                'requested_by_name_snapshot' => $actor->name,
                'requested_by_reference_snapshot' => 'user:'.$actor->id,
                'requested_at' => now(),
            ]);

            $this->audit->log($actor, 'stage4c.credit_reversal.initiated', 'Requested a customer credit reversal.', $request, null, [
                'ledger_entry_id' => $entry->id,
                'amount' => PaymentAllocationService::formatMinorUnits($minor),
                'currency' => $entry->currency,
                'operation_reference' => $operationReference,
            ]);

            return $request;
        }, 3);
    }

    public function approve(int $requestId, User $actor): CreditReversalRequest
    {
        return DB::transaction(function () use ($requestId, $actor): CreditReversalRequest {
            $request = CreditReversalRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($request->status === CreditReversalRequest::STATUS_APPROVED) return $request;
            if ($request->status !== CreditReversalRequest::STATUS_REQUESTED) {
                throw new DomainException('Only pending reversal requests can be approved.');
            }
            if ($this->policy->reversalSeparationRequired() && (int) $request->requested_by === (int) $actor->id) {
                throw new DomainException('A different staff member must approve a credit reversal.');
            }

            $snapshot = CustomerCreditLedgerEntry::query()->find($request->ledger_entry_id);
            if (! $snapshot) throw new DomainException('The original credit entry no longer exists.');
            $payer = $this->payerFor($snapshot);
            $this->lockPayer($payer);
            $entry = CustomerCreditLedgerEntry::query()->lockForUpdate()->findOrFail($request->ledger_entry_id);

            $minor = PaymentAllocationService::toMinorUnits((string) $request->amount);
            $remaining = $this->remainingReversibleMinor($entry);
            if ($minor > $remaining) {
                throw new DomainException('The credit is no longer reversible: it has been consumed or already reversed.');
            }

            $balanceBefore = $this->credit->currentBalanceMinor($payer, $entry->currency, true);
            $reversal = CustomerCreditLedgerEntry::query()->create([
                'entry_type' => CustomerCreditLedgerEntry::TYPE_REVERSAL,
                'operation_reference' => $request->operation_reference,
                'source_payment_transaction_id' => null,
                'commercial_document_id' => null,
                'reverses_entry_id' => $entry->id,
                'customer_id' => $entry->customer_id,
                'organisation_id' => $entry->organisation_id,
                'actor_user_id' => $request->requested_by,
                'actor_user_id_snapshot' => $request->requested_by_id_snapshot,
                'actor_name_snapshot' => $request->requested_by_name_snapshot,
                'actor_reference_snapshot' => $request->requested_by_reference_snapshot,
                'authorized_by_user_id' => $actor->id,
                'authorized_by_name_snapshot' => $actor->name,
                'authorized_at' => now(),
                'amount' => PaymentAllocationService::formatMinorUnits($minor),
                'currency' => $entry->currency,
                'reason' => trim((string) $request->reason),
                'credit_balance_before' => PaymentAllocationService::formatMinorUnits($balanceBefore),
                'credit_balance_after' => PaymentAllocationService::formatMinorUnits($balanceBefore - $minor),
                'created_at' => now(),
            ]);

            $request->forceFill([
                'status' => CreditReversalRequest::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'reversal_ledger_entry_id' => $reversal->id,
            ])->save();

            $this->audit->log($actor, 'stage4c.credit_reversal.approved', 'Approved a customer credit reversal.', $request, null, [
                'reversal_ledger_entry_id' => $reversal->id,
                'amount' => $reversal->amount,
                'currency' => $reversal->currency,
                'balance_before' => $reversal->credit_balance_before,
                'balance_after' => $reversal->credit_balance_after,
            ]);

            return $request->fresh(['ledgerEntry', 'reversalEntry']);
        }, 3);
    }

    public function reject(int $requestId, string $reason, User $actor): CreditReversalRequest
    {
        if (trim($reason) === '') throw new DomainException('A rejection reason is required.');

        return DB::transaction(function () use ($requestId, $reason, $actor): CreditReversalRequest {
            $request = CreditReversalRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($request->status === CreditReversalRequest::STATUS_REJECTED) return $request;
            if ($request->status !== CreditReversalRequest::STATUS_REQUESTED) {
                throw new DomainException('Only pending reversal requests can be rejected.');
            }
            $request->forceFill([
                'status' => CreditReversalRequest::STATUS_REJECTED,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ])->save();
            $this->audit->log($actor, 'stage4c.credit_reversal.rejected', 'Rejected a customer credit reversal.', $request, null, ['reason' => trim($reason)]);
            return $request;
        }, 3);
    }

    /**
     * Remaining reversible amount for one credit entry.
     *
     * Applications are attributed to credit entries chronologically (FIFO)
     * within the same payer and currency, so already-spent credit can never be
     * reversed. Prior reversals of the same entry are then deducted.
     */
    public function remainingReversibleMinor(CustomerCreditLedgerEntry $entry): int
    {
        $payer = $this->payerFor($entry);
        $credits = $this->payerQuery($payer)
            ->where('currency', $entry->currency)
            ->whereIn('entry_type', CustomerCreditLedgerEntry::CREDIT_TYPES)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'amount']);

        // Credit already reversed is no longer available for applications to
        // consume, so applications are attributed FIFO across each entry's
        // un-reversed amount. Summing the remaining per entry then equals the
        // derived ledger balance.
        $reversedByEntry = $this->payerQuery($payer)
            ->where('currency', $entry->currency)
            ->where('entry_type', CustomerCreditLedgerEntry::TYPE_REVERSAL)
            ->whereNotNull('reverses_entry_id')
            ->select('reverses_entry_id')
            ->selectRaw('SUM(amount) as reversed_total')
            ->groupBy('reverses_entry_id')
            ->pluck('reversed_total', 'reverses_entry_id');

        $remainingConsumption = PaymentAllocationService::toMinorUnits((string) ($this->payerQuery($payer)
            ->where('currency', $entry->currency)
            ->where('entry_type', CustomerCreditLedgerEntry::TYPE_APPLICATION)
            ->sum('amount') ?: '0'));

        $remaining = 0;
        foreach ($credits as $credit) {
            $reversed = PaymentAllocationService::toMinorUnits((string) ($reversedByEntry[$credit->id] ?? '0'));
            $available = max(0, PaymentAllocationService::toMinorUnits((string) $credit->amount) - $reversed);
            $take = min($available, $remainingConsumption);
            $remainingConsumption -= $take;
            if ((int) $credit->id === (int) $entry->id) {
                $remaining = $available - $take;
                break;
            }
        }

        return max(0, $remaining);
    }

    private function payerQuery(Customer|Organisation $payer)
    {
        $query = CustomerCreditLedgerEntry::query();
        return $payer instanceof Customer
            ? $query->where('customer_id', $payer->id)->whereNull('organisation_id')
            : $query->where('organisation_id', $payer->id)->whereNull('customer_id');
    }

    private function entrySummary(CustomerCreditLedgerEntry $entry): array
    {
        $payer = $entry->customer_id !== null
            ? ['type' => 'customer', 'id' => $entry->customer?->id, 'name' => $entry->customer?->name]
            : ['type' => 'organisation', 'id' => $entry->organisation?->id, 'name' => $entry->organisation?->name];

        return [
            'ledger_entry_id' => $entry->id,
            'entry_type' => $entry->entry_type,
            'operation_reference' => $entry->operation_reference,
            'payer' => $payer,
            'amount' => $entry->amount,
            'currency' => $entry->currency,
            'reason' => $entry->reason,
            'source_reference' => $entry->sourcePayment?->transaction_reference,
            'document_number' => $entry->commercialDocument?->document_number,
            'created_at' => $entry->created_at,
        ];
    }

    private function requestView(CreditReversalRequest $request): array
    {
        return [
            'id' => $request->id,
            'operation_reference' => $request->operation_reference,
            'status' => $request->status,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'reason' => $request->reason,
            'payer' => $request->customer_id !== null
                ? ['type' => 'customer', 'id' => $request->customer?->id, 'name' => $request->customer?->name]
                : ['type' => 'organisation', 'id' => $request->organisation?->id, 'name' => $request->organisation?->name],
            'original_entry' => $request->ledgerEntry ? [
                'id' => $request->ledgerEntry->id,
                'reference' => $request->ledgerEntry->operation_reference,
                'entry_type' => $request->ledgerEntry->entry_type,
                'amount' => $request->ledgerEntry->amount,
                'created_at' => $request->ledgerEntry->created_at,
            ] : null,
            'requested_by' => $request->requested_by_name_snapshot ?: $request->requester?->name,
            'requested_at' => $request->requested_at,
            'approved_by' => $request->approver?->name,
            'approved_at' => $request->approved_at,
            'rejected_at' => $request->rejected_at,
            'rejection_reason' => $request->rejection_reason,
            'reversal_ledger_entry_id' => $request->reversal_ledger_entry_id,
        ];
    }

    private function payerFor(CustomerCreditLedgerEntry $entry): Customer|Organisation
    {
        if ($entry->organisation_id !== null) return Organisation::query()->findOrFail($entry->organisation_id);
        if ($entry->customer_id !== null) return Customer::query()->findOrFail($entry->customer_id);
        throw new DomainException('The credit entry has no payer.');
    }

    private function lockPayer(Customer|Organisation $payer): void
    {
        $table = $payer instanceof Customer ? 'customers' : 'organisations';
        if (! DB::table($table)->where('id', $payer->id)->lockForUpdate()->first()) {
            throw new DomainException('The credit payer no longer exists.');
        }
    }

    private function assertSameOperation(CreditReversalRequest $existing, CustomerCreditLedgerEntry $entry, int $minor, string $reason, User $actor): void
    {
        if ((int) $existing->ledger_entry_id !== (int) $entry->id
            || PaymentAllocationService::toMinorUnits((string) $existing->amount) !== $minor
            || trim((string) $existing->reason) !== trim($reason)
            || (int) $existing->requested_by !== (int) $actor->id) {
            throw new DomainException('This operation reference was already used for a different credit reversal.');
        }
    }

    private function assertReference(string $reference): void
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $reference)) {
            throw new DomainException('The operation reference is invalid.');
        }
    }
}
