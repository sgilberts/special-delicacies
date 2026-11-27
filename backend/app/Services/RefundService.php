<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\Organisation;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stage 4C refund domain.
 *
 * A refund is never a payment reversal: it is a separately authorised money
 * movement anchored to a verified payment, with its own lifecycle, evidence and
 * audit trail. Status changes are enforced here and nowhere else.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentAllocationService $allocations,
        private readonly CustomerCreditService $credit,
        private readonly RefundGatewayService $gateway,
        private readonly AuditService $audit,
    ) {}

    public function eligiblePayments(array $filters = [])
    {
        $reserving = implode("','", Refund::RESERVING_STATUSES);
        $query = PaymentTransaction::query()
            ->with(['customer:id,name,email,phone', 'organisation:id,name,reference', 'order:id,order_number'])
            ->where('status', 'succeeded')->whereNotNull('verified_at')->whereNotNull('received_at')
            ->where('amount', '>', 0)
            ->where(function ($payer): void {
                $payer->where(fn ($individual) => $individual->whereNotNull('customer_id')->whereNull('organisation_id'))
                    ->orWhere(fn ($organisation) => $organisation->whereNull('customer_id')->whereNotNull('organisation_id'));
            })
            ->whereRaw("payment_transactions.amount > COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.payment_transaction_id = payment_transactions.id AND r.status IN ('{$reserving}')), 0)")
            ->orderByDesc('received_at')->orderByDesc('id');

        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn ($search) => $search
                ->where('transaction_reference', 'like', $term)
                ->orWhere('provider_reference', 'like', $term)
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $term)->orWhere('email', 'like', $term))
                ->orWhereHas('organisation', fn ($organisation) => $organisation->where('name', 'like', $term)->orWhere('reference', 'like', $term)));
        }

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 20), 100)))
            ->through(fn (PaymentTransaction $payment) => $this->paymentRow($payment));
    }

    public function paymentContext(int $paymentId): array
    {
        $payment = PaymentTransaction::query()
            ->with(['customer:id,name,email,phone', 'organisation:id,name,reference', 'order:id,order_number'])
            ->findOrFail($paymentId);
        $this->assertRefundablePayment($payment);

        $refunds = Refund::query()->with(['initiator:id,name', 'approver:id,name'])
            ->where('payment_transaction_id', $payment->id)->orderByDesc('id')->get();

        return $this->paymentRow($payment) + [
            'allocations' => PaymentAllocation::query()
                ->with(['operation:id,payment_transaction_id,operation_reference', 'commercialDocument:id,document_number,document_type,currency,total', 'reversal:id,payment_allocation_id'])
                ->whereHas('operation', fn ($query) => $query->where('payment_transaction_id', $payment->id))
                ->orderBy('id')->get()
                ->map(fn (PaymentAllocation $allocation) => [
                    'id' => $allocation->id,
                    'document_id' => $allocation->commercial_document_id,
                    'document_number' => $allocation->commercialDocument?->document_number,
                    'amount' => $allocation->amount,
                    'currency' => $allocation->currency,
                    'reversed' => $allocation->reversal !== null,
                ])->values(),
            'refunds' => $refunds->map(fn (Refund $refund) => $this->rowView($refund))->values(),
        ];
    }

    public function list(array $filters = [])
    {
        $query = Refund::query()
            ->with(['customer:id,name,email,phone', 'organisation:id,name,reference', 'payment:id,transaction_reference,provider,payment_method',
                'commercialDocument:id,document_number', 'initiator:id,name', 'approver:id,name'])
            ->latest('id');

        if (filled($filters['status'] ?? null)) $query->where('status', $filters['status']);
        if (filled($filters['refund_method'] ?? null)) $query->where('refund_method', $filters['refund_method']);
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn ($search) => $search
                ->where('refund_reference', 'like', $term)
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $term)->orWhere('email', 'like', $term))
                ->orWhereHas('organisation', fn ($organisation) => $organisation->where('name', 'like', $term)->orWhere('reference', 'like', $term))
                ->orWhereHas('payment', fn ($payment) => $payment->where('transaction_reference', 'like', $term)));
        }

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (Refund $refund) => $this->rowView($refund));
    }

    public function present(Refund $refund): array
    {
        $refund->loadMissing(['customer:id,name,email,phone', 'organisation:id,name,reference', 'order:id,order_number',
            'commercialDocument:id,document_number,document_type,total,currency', 'initiator:id,name', 'approver:id,name',
            'creditLedgerEntry:id,amount,currency,credit_balance_before,credit_balance_after,created_at']);
        $payment = $refund->payment()->with(['customer:id,name', 'organisation:id,name'])->first();
        $allocation = $refund->allocation()->with('commercialDocument:id,document_number')->first();
        $gateway = $payment ? $this->gateway->gatewayForPayment($payment) : null;

        return $this->rowView($refund) + [
            'reason' => $refund->reason,
            'notes' => $refund->notes,
            'allocation' => $allocation ? [
                'id' => $allocation->id,
                'document_number' => $allocation->commercialDocument?->document_number,
                'amount' => $allocation->amount,
            ] : null,
            'initiated_by' => $refund->initiated_by_name_snapshot ?: $refund->initiator?->name,
            'approved_by' => $refund->approved_by_name_snapshot ?: $refund->approver?->name,
            'reviewed_at' => $refund->reviewed_at,
            'approved_at' => $refund->approved_at,
            'processing_at' => $refund->processing_at,
            'completed_at' => $refund->completed_at,
            'failed_at' => $refund->failed_at,
            'cancelled_at' => $refund->cancelled_at,
            'reversed_at' => $refund->reversed_at,
            'rejection_reason' => $refund->rejection_reason,
            'failure_reason' => $refund->failure_reason,
            'cancellation_reason' => $refund->cancellation_reason,
            'reversal_reason' => $refund->reversal_reason,
            'gateway_reference' => $refund->gateway_reference,
            'gateway_status' => $refund->gateway_status,
            'external_transaction_reference' => $refund->external_transaction_reference,
            'gateway_provider' => $gateway?->provider,
            'automated_gateway_refund_available' => $this->gateway->supportsAutomatedRefunds($gateway),
            'refundable_balance' => $payment ? PaymentAllocationService::formatMinorUnits($this->refundableMinor($payment, $refund->id)) : '0.00',
            'credit_ledger_entry' => $refund->creditLedgerEntry ? [
                'id' => $refund->creditLedgerEntry->id,
                'amount' => $refund->creditLedgerEntry->amount,
                'currency' => $refund->creditLedgerEntry->currency,
                'credit_balance_before' => $refund->creditLedgerEntry->credit_balance_before,
                'credit_balance_after' => $refund->creditLedgerEntry->credit_balance_after,
                'created_at' => $refund->creditLedgerEntry->created_at,
            ] : null,
            'created_at' => $refund->created_at,
            'requested_at' => $refund->requested_at,
        ];
    }

    public function history(Refund $refund): array
    {
        return AdminAuditLog::query()->with('user:id,name')
            ->where('entity_type', Refund::class)->where('entity_id', (string) $refund->id)
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

    public function create(User $actor, array $input, ?Request $request = null): Refund
    {
        $minor = PaymentAllocationService::toMinorUnits((string) $input['amount']);
        if ($minor <= 0) throw new DomainException('The refund amount must be greater than zero.');
        $method = (string) $input['refund_method'];
        if (! in_array($method, [Refund::METHOD_ORIGINAL, Refund::METHOD_CUSTOMER_CREDIT, Refund::METHOD_MANUAL], true)) {
            throw new DomainException('Choose a supported refund method.');
        }
        $reason = trim((string) $input['reason']);
        if (mb_strlen($reason) < 3) throw new DomainException('A refund reason is required.');

        return DB::transaction(function () use ($actor, $input, $method, $minor, $reason, $request): Refund {
                $payment = PaymentTransaction::query()->lockForUpdate()->find((int) $input['payment_transaction_id']);
                if (! $payment) throw new DomainException('The original payment could not be found.');
                $this->assertRefundablePayment($payment);

                $existing = Refund::query()->where('operation_reference', (string) $input['operation_reference'])->first();
                if ($existing) {
                    $same = (int) $existing->payment_transaction_id === (int) $payment->id
                        && $existing->refund_method === $method
                        && PaymentAllocationService::toMinorUnits((string) $existing->amount) === $minor
                        && trim($existing->reason) === $reason
                        && (int) $existing->initiated_by === (int) $actor->id;
                    if ($same) return $existing;
                    throw new DomainException('This operation reference was already used for a different refund.');
                }

                if (($input['currency'] ?? $payment->currency) !== $payment->currency) {
                    throw new DomainException('The refund currency must match the original payment.');
                }
                $refundable = $this->refundableMinor($payment);
                if ($minor > $refundable) {
                    throw new DomainException('The refund exceeds the refundable balance of '.PaymentAllocationService::formatMinorUnits($refundable).'.');
                }

                $document = null;
                if (! empty($input['commercial_document_id'])) {
                    $document = CommercialDocument::query()->find((int) $input['commercial_document_id']);
                    if (! $document) throw new DomainException('The related commercial document could not be found.');
                    $this->assertDocumentMatchesPayer($document, $payment);
                }

                $allocation = null;
                if (! empty($input['payment_allocation_id'])) {
                    $allocation = PaymentAllocation::query()->with('operation')->find((int) $input['payment_allocation_id']);
                    if (! $allocation || (int) $allocation->operation?->payment_transaction_id !== (int) $payment->id) {
                        throw new DomainException('The payment allocation does not belong to the original payment.');
                    }
                    if ($allocation->reversal()->exists()) throw new DomainException('A reversed allocation cannot back a refund.');
                    if ($document && (int) $allocation->commercial_document_id !== (int) $document->id) {
                        throw new DomainException('The allocation and the commercial document do not match.');
                    }
                    if ($document === null) $document = CommercialDocument::query()->find($allocation->commercial_document_id);
                }

                $refund = Refund::query()->create([
                    'status' => Refund::STATUS_DRAFT,
                    'refund_method' => $method,
                    'payment_transaction_id' => $payment->id,
                    'payment_allocation_id' => $allocation?->id,
                    'order_id' => $payment->order_id,
                    'commercial_document_id' => $document?->id,
                    'customer_id' => $payment->customer_id,
                    'organisation_id' => $payment->organisation_id,
                    'amount' => PaymentAllocationService::formatMinorUnits($minor),
                    'currency' => $payment->currency,
                    'reason' => $reason,
                    'notes' => filled($input['notes'] ?? null) ? trim((string) $input['notes']) : null,
                    'operation_reference' => (string) $input['operation_reference'],
                    'initiated_by' => $actor->id,
                    'initiated_by_snapshot' => $actor->id,
                    'initiated_by_name_snapshot' => $actor->name,
                    'initiated_by_reference_snapshot' => 'user:'.$actor->id,
                    'requested_at' => now(),
                ]);
                $refund->forceFill(['refund_reference' => sprintf('REF-%s-%08d', now()->format('Y'), $refund->id)])->save();

                $this->record('refund.created', $refund, $actor, "Refund {$refund->refund_reference} drafted for {$refund->currency} {$refund->amount}.", null, [
                    'status' => $refund->status, 'amount' => $refund->amount, 'refund_method' => $refund->refund_method,
                ], $request);

                return $refund->fresh(['customer', 'organisation', 'payment', 'commercialDocument', 'initiator']);
            }, 3);
    }

    public function submit(User $actor, Refund $refund, ?Request $request = null): Refund
    {
        return $this->transition($refund, [Refund::STATUS_DRAFT], Refund::STATUS_SUBMITTED, $actor, 'refund.submitted', $request, function (Refund $locked) use ($actor): void {
            $payment = PaymentTransaction::query()->lockForUpdate()->find($locked->payment_transaction_id);
            if (! $payment) throw new DomainException('The original payment could not be found.');
            $this->assertRefundablePayment($payment);
            $minor = PaymentAllocationService::toMinorUnits((string) $locked->amount);
            $refundable = $this->refundableMinor($payment, $locked->id);
            if ($minor > $refundable) {
                throw new DomainException('The refund exceeds the remaining refundable balance of '.PaymentAllocationService::formatMinorUnits($refundable).'.');
            }
        }, "Refund {$refund->refund_reference} submitted for review.");
    }

    public function review(User $actor, Refund $refund, ?Request $request = null): Refund
    {
        return $this->transition($refund, [Refund::STATUS_SUBMITTED], Refund::STATUS_PENDING_REVIEW, $actor, 'refund.reviewed', $request, function (Refund $locked) use ($actor): void {
            $locked->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        }, "Refund {$refund->refund_reference} moved to pending review.");
    }

    public function approve(User $actor, Refund $refund, ?Request $request = null): Refund
    {
        return $this->transition($refund, [Refund::STATUS_SUBMITTED, Refund::STATUS_PENDING_REVIEW], Refund::STATUS_APPROVED, $actor, 'refund.approved', $request, function (Refund $locked) use ($actor): void {
            if ((int) $locked->initiated_by === (int) $actor->id) {
                throw new DomainException('A refund must be approved by a different user from the one who requested it.');
            }
            $locked->forceFill([
                'approved_by' => $actor->id, 'approved_by_name_snapshot' => $actor->name, 'approved_at' => now(),
            ]);
        }, "Refund {$refund->refund_reference} approved.");
    }

    public function reject(User $actor, Refund $refund, string $reason, ?Request $request = null): Refund
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new DomainException('A rejection reason is required.');
        return $this->transition($refund, [Refund::STATUS_SUBMITTED, Refund::STATUS_PENDING_REVIEW], Refund::STATUS_REJECTED, $actor, 'refund.rejected', $request, function (Refund $locked) use ($actor, $reason): void {
            if ((int) $locked->initiated_by === (int) $actor->id) {
                throw new DomainException('A refund must be reviewed by a different user from the one who requested it.');
            }
            $locked->forceFill(['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
        }, "Refund {$refund->refund_reference} rejected: {$reason}", ['reason' => $reason]);
    }

    public function cancel(User $actor, Refund $refund, string $reason, ?Request $request = null): Refund
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new DomainException('A cancellation reason is required.');
        return $this->transition($refund, [Refund::STATUS_DRAFT, Refund::STATUS_SUBMITTED, Refund::STATUS_PENDING_REVIEW, Refund::STATUS_APPROVED], Refund::STATUS_CANCELLED, $actor, 'refund.cancelled', $request, function (Refund $locked) use ($actor, $reason): void {
            $locked->forceFill(['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
        }, "Refund {$refund->refund_reference} cancelled: {$reason}", ['reason' => $reason]);
    }

    public function reverse(User $actor, Refund $refund, string $reason, ?Request $request = null): Refund
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new DomainException('A reversal reason is required.');
        // A reversal records verified correction evidence. It never moves money
        // and never edits an immutable ledger entry; a further refund would be a
        // new, separately authorised record.
        return $this->transition($refund, [Refund::STATUS_COMPLETED], Refund::STATUS_REVERSED, $actor, 'refund.reversed', $request, function (Refund $locked) use ($actor, $reason): void {
            $locked->forceFill(['reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
        }, "Refund {$refund->refund_reference} reversed: {$reason}", ['reason' => $reason]);
    }

    public function fail(User $actor, Refund $refund, string $reason, ?Request $request = null): Refund
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new DomainException('A failure reason is required.');
        return $this->transition($refund, [Refund::STATUS_PROCESSING], Refund::STATUS_FAILED, $actor, 'refund.failed', $request, function (Refund $locked) use ($reason): void {
            $locked->forceFill(['failed_at' => now(), 'failure_reason' => $reason]);
        }, "Refund {$refund->refund_reference} failed: {$reason}", ['reason' => $reason]);
    }

    public function process(User $actor, Refund $refund, ?Request $request = null): Refund
    {
        $locked = DB::transaction(function () use ($refund): Refund {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== Refund::STATUS_APPROVED) {
                throw new DomainException('Only an approved refund can be processed.');
            }
            return $locked;
        }, 3);

        if ($locked->refund_method === Refund::METHOD_CUSTOMER_CREDIT) {
            return $this->processCustomerCredit($locked, $actor, $request);
        }

        $locked = DB::transaction(function () use ($locked, $actor, $request): Refund {
            $fresh = Refund::query()->lockForUpdate()->findOrFail($locked->id);
            if ($fresh->status !== Refund::STATUS_APPROVED) throw new DomainException('Only an approved refund can be processed.');
            $fresh->forceFill(['status' => Refund::STATUS_PROCESSING, 'processing_at' => now()])->save();
            $this->record('refund.processing', $fresh, $actor, "Refund {$fresh->refund_reference} approved for processing.", ['status' => Refund::STATUS_APPROVED], ['status' => Refund::STATUS_PROCESSING], $request);
            return $fresh->fresh();
        }, 3);

        if ($locked->refund_method === Refund::METHOD_MANUAL) return $locked;

        $payment = PaymentTransaction::query()->findOrFail($locked->payment_transaction_id);
        $outcome = $this->gateway->submit($locked, $payment);

        return $this->applyGatewayOutcome($locked, $outcome['status'], $outcome['gateway_reference'], $outcome['message'], null, null, $actor, $request);
    }

    public function complete(User $actor, Refund $refund, ?string $evidenceReference, ?Request $request = null): Refund
    {
        $evidence = $evidenceReference !== null ? trim($evidenceReference) : '';
        return DB::transaction(function () use ($refund, $actor, $evidence, $request): Refund {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== Refund::STATUS_PROCESSING) {
                throw new DomainException('Only a processing refund can be completed.');
            }
            if ($locked->refund_method === Refund::METHOD_CUSTOMER_CREDIT) {
                throw new DomainException('A customer credit refund completes through its ledger posting.');
            }
            if ($locked->refund_method === Refund::METHOD_MANUAL && mb_strlen($evidence) < 3) {
                throw new DomainException('Record the external transaction reference that evidences the manual refund.');
            }
            if ($locked->refund_method === Refund::METHOD_ORIGINAL && $evidence === '' && blank($locked->gateway_reference)) {
                throw new DomainException('A refund can only be completed with a provider reference or verified evidence.');
            }
            $old = $locked->status;
            $locked->forceFill([
                'status' => Refund::STATUS_COMPLETED,
                'completed_at' => now(),
                'external_transaction_reference' => $evidence !== '' ? $evidence : $locked->external_transaction_reference,
            ])->save();
            $this->record('refund.completed', $locked, $actor, "Refund {$locked->refund_reference} completed.", ['status' => $old], [
                'status' => $locked->status, 'evidence' => $evidence !== '' ? $evidence : $locked->gateway_reference,
            ], $request);
            return $locked->fresh();
        }, 3);
    }

    public function retry(User $actor, Refund $refund, ?Request $request = null): Refund
    {
        $locked = DB::transaction(function () use ($refund, $actor, $request): Refund {
            $fresh = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($fresh->status !== Refund::STATUS_FAILED) throw new DomainException('Only a failed refund can be retried.');
            if ($fresh->refund_method === Refund::METHOD_CUSTOMER_CREDIT) {
                throw new DomainException('A customer credit refund never fails in a retryable way; post it again from approved.');
            }
            $fresh->forceFill([
                'status' => Refund::STATUS_PROCESSING, 'processing_at' => now(),
                'failed_at' => null, 'failure_reason' => null,
            ])->save();
            $this->record('refund.retried', $fresh, $actor, "Refund {$fresh->refund_reference} retried.", ['status' => Refund::STATUS_FAILED], ['status' => Refund::STATUS_PROCESSING], $request);
            return $fresh->fresh();
        }, 3);

        if ($locked->refund_method === Refund::METHOD_MANUAL) return $locked;

        $payment = PaymentTransaction::query()->findOrFail($locked->payment_transaction_id);
        // Never submit the same provider refund twice: verify the existing
        // provider reference instead of issuing a second money movement.
        $outcome = filled($locked->gateway_reference)
            ? $this->gateway->verify($locked, $payment)
            : $this->gateway->submit($locked, $payment);

        return $this->applyGatewayOutcome($locked, $outcome['status'], $outcome['gateway_reference'], $outcome['message'], null, null, $actor, $request);
    }

    public function verifyGateway(User $actor, Refund $refund, ?Request $request = null): Refund
    {
        $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
        if ($locked->status !== Refund::STATUS_PROCESSING) {
            throw new DomainException('Only a processing refund can be verified with the provider.');
        }
        if ($locked->refund_method !== Refund::METHOD_ORIGINAL) {
            throw new DomainException('Only gateway refunds can be verified with the provider.');
        }
        $payment = PaymentTransaction::query()->findOrFail($locked->payment_transaction_id);
        $outcome = $this->gateway->verify($locked, $payment);

        return $this->applyGatewayOutcome($locked, $outcome['status'], $outcome['gateway_reference'], $outcome['message'], null, null, $actor, $request);
    }

    /**
     * Reconcile verified provider evidence (API verification or authenticated
     * webhook). Amount and currency must match the refund record.
     */
    public function applyGatewayOutcome(
        Refund $refund,
        string $gatewayStatus,
        ?string $gatewayReference,
        ?string $message = null,
        ?int $amountMinor = null,
        ?string $currency = null,
        ?User $actor = null,
        ?Request $request = null,
    ): Refund {
        $status = strtolower($gatewayStatus);
        return DB::transaction(function () use ($refund, $status, $gatewayReference, $message, $amountMinor, $currency, $actor, $request): Refund {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($amountMinor !== null && $amountMinor !== PaymentAllocationService::toMinorUnits((string) $locked->amount)) return $locked;
            if ($currency !== null && $currency !== strtoupper((string) $locked->currency)) return $locked;
            if (in_array($locked->status, [Refund::STATUS_CANCELLED, Refund::STATUS_REJECTED, Refund::STATUS_REVERSED, Refund::STATUS_DRAFT], true)) return $locked;

            $attributes = ['gateway_status' => $status];
            if (filled($gatewayReference)) $attributes['gateway_reference'] = $gatewayReference;

            if (in_array($status, ['processed', 'completed', 'success', 'succeeded'], true)) {
                if ($locked->status === Refund::STATUS_COMPLETED) {
                    $locked->forceFill($attributes)->save();
                    return $locked->fresh();
                }
                if (! in_array($locked->status, [Refund::STATUS_PROCESSING, Refund::STATUS_APPROVED], true)) return $locked;
                $old = $locked->status;
                $locked->forceFill($attributes + ['status' => Refund::STATUS_COMPLETED, 'completed_at' => now()])->save();
                $this->record('refund.completed', $locked, $actor, "Refund {$locked->refund_reference} confirmed completed by the provider.", ['status' => $old], [
                    'status' => $locked->status, 'gateway_status' => $status,
                ], $request);
                return $locked->fresh();
            }

            if (in_array($status, ['failed', 'reversed', 'declined'], true)) {
                $old = $locked->status;
                $locked->forceFill($attributes + ['status' => Refund::STATUS_FAILED, 'failed_at' => now(), 'failure_reason' => $message ?: 'The provider reported the refund as failed.'])->save();
                $this->record('refund.failed', $locked, $actor, "Refund {$locked->refund_reference} reported failed by the provider.", ['status' => $old], [
                    'status' => $locked->status, 'gateway_status' => $status,
                ], $request);
                return $locked->fresh();
            }

            // Pending or processing: record the provider evidence without
            // claiming the money has reached the customer.
            if ($locked->status === Refund::STATUS_APPROVED) $attributes['status'] = Refund::STATUS_PROCESSING;
            if ($locked->status === Refund::STATUS_APPROVED && ! isset($attributes['processing_at'])) $attributes['processing_at'] = now();
            $locked->forceFill($attributes)->save();
            return $locked->fresh();
        }, 3);
    }

    public function refundableMinor(PaymentTransaction $payment, ?int $excludeRefundId = null): int
    {
        $query = Refund::query()->where('payment_transaction_id', $payment->id)->whereIn('status', Refund::RESERVING_STATUSES);
        if ($excludeRefundId !== null) $query->whereKeyNot($excludeRefundId);
        $reserved = PaymentAllocationService::toMinorUnits((string) ($query->sum('amount') ?: '0'));
        return max(0, PaymentAllocationService::toMinorUnits((string) $payment->amount) - $reserved);
    }

    private function processCustomerCredit(Refund $refund, User $actor, ?Request $request): Refund
    {
        return DB::transaction(function () use ($refund, $actor, $request): Refund {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== Refund::STATUS_APPROVED) throw new DomainException('Only an approved refund can be processed.');
            $entry = $this->credit->postRefundCredit($locked, $actor);
            $old = $locked->status;
            $locked->forceFill([
                'status' => Refund::STATUS_COMPLETED,
                'processing_at' => now(),
                'completed_at' => now(),
                'credit_ledger_entry_id' => $entry->id,
                'external_transaction_reference' => 'ledger:'.$entry->id,
            ])->save();
            $this->record('refund.credit_posted', $locked, $actor, "Refund {$locked->refund_reference} posted to the customer credit ledger.", null, [
                'ledger_entry_id' => $entry->id, 'amount' => $entry->amount, 'currency' => $entry->currency,
            ], $request);
            $this->record('refund.completed', $locked, $actor, "Refund {$locked->refund_reference} completed as customer credit.", ['status' => $old], [
                'status' => $locked->status,
            ], $request);
            return $locked->fresh(['creditLedgerEntry']);
        }, 3);
    }

    private function transition(Refund $refund, array $from, string $to, User $actor, string $action, ?Request $request, callable $mutate, string $description, ?array $newValues = null): Refund
    {
        return DB::transaction(function () use ($refund, $from, $to, $actor, $action, $request, $mutate, $description, $newValues): Refund {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if (! in_array($locked->status, $from, true)) {
                throw new DomainException("This refund cannot move from {$locked->status} to {$to}.");
            }
            $old = $locked->status;
            $locked->status = $to;
            $mutate($locked);
            $locked->save();
            $this->record($action, $locked, $actor, $description, ['status' => $old], ($newValues ?? []) + ['status' => $to], $request);
            return $locked->fresh(['customer', 'organisation', 'payment', 'commercialDocument', 'initiator', 'approver', 'creditLedgerEntry']);
        }, 3);
    }

    private function assertRefundablePayment(PaymentTransaction $payment): void
    {
        if ($payment->status !== 'succeeded' || ! $payment->verified_at || ! $payment->received_at) {
            throw new DomainException('Only verified, received successful payments can be refunded.');
        }
        if (PaymentAllocationService::toMinorUnits((string) $payment->amount) <= 0) {
            throw new DomainException('The payment has no refundable amount.');
        }
        if (($payment->customer_id !== null) === ($payment->organisation_id !== null)) {
            throw new DomainException('The payment must have exactly one identified payer.');
        }
    }

    private function assertDocumentMatchesPayer(CommercialDocument $document, PaymentTransaction $payment): void
    {
        if ($document->currency !== $payment->currency) throw new DomainException('The document currency does not match the payment.');
        $samePayer = $payment->organisation_id !== null
            ? (int) $document->organisation_id === (int) $payment->organisation_id
            : $document->organisation_id === null && (int) $document->customer_id === (int) $payment->customer_id;
        if (! $samePayer) throw new DomainException('The commercial document does not belong to the payment payer.');
    }

    private function record(string $action, Refund $refund, ?User $actor, string $description, ?array $old = null, ?array $new = null, ?Request $request = null): void
    {
        $this->audit->log($actor, $action, $description, $refund, $old, $new, $request);
    }

    private function paymentRow(PaymentTransaction $payment): array
    {
        return [
            'id' => $payment->id,
            'transaction_reference' => $payment->transaction_reference,
            'provider_reference' => $payment->provider_reference,
            'provider' => $payment->provider,
            'payment_method' => $payment->payment_method,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'verified_at' => $payment->verified_at,
            'received_at' => $payment->received_at,
            'order' => $payment->order ? ['id' => $payment->order->id, 'order_number' => $payment->order->order_number] : null,
            'payer' => $payment->organisation
                ? ['type' => 'organisation', 'id' => $payment->organisation->id, 'name' => $payment->organisation->name]
                : ($payment->customer ? ['type' => 'customer', 'id' => $payment->customer->id, 'name' => $payment->customer->name] : null),
            'refundable_balance' => PaymentAllocationService::formatMinorUnits($this->refundableMinor($payment)),
            'allocated_amount' => PaymentAllocationService::formatMinorUnits($this->allocations->paymentAllocatedMinorForPayment($payment->id)),
            'credit_designated_amount' => PaymentAllocationService::formatMinorUnits($this->allocations->creditDesignatedMinorForPayment($payment->id)),
            'unallocated_amount' => PaymentAllocationService::formatMinorUnits($this->allocations->availableMinorForPayment($payment->id)),
        ];
    }

    private function rowView(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'refund_reference' => $refund->refund_reference,
            'status' => $refund->status,
            'refund_method' => $refund->refund_method,
            'amount' => $refund->amount,
            'currency' => $refund->currency,
            'payer' => $refund->organisation
                ? ['type' => 'organisation', 'id' => $refund->organisation->id, 'name' => $refund->organisation->name]
                : ($refund->customer ? ['type' => 'customer', 'id' => $refund->customer->id, 'name' => $refund->customer->name] : null),
            'payment' => $refund->payment ? [
                'id' => $refund->payment->id,
                'transaction_reference' => $refund->payment->transaction_reference,
                'provider' => $refund->payment->provider,
                'payment_method' => $refund->payment->payment_method,
            ] : null,
            'order' => $refund->order ? ['id' => $refund->order->id, 'order_number' => $refund->order->order_number] : null,
            'commercial_document' => $refund->commercialDocument ? [
                'id' => $refund->commercialDocument->id,
                'document_number' => $refund->commercialDocument->document_number,
            ] : null,
            'initiator' => $refund->initiated_by_name_snapshot ?: $refund->initiator?->name,
            'approver' => $refund->approved_by_name_snapshot ?: $refund->approver?->name,
        ];
    }
}
