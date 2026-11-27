<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\RefundEvent;
use App\Models\RefundRequest;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RefundService
{
    public function __construct(
        private readonly PaymentAllocationService $allocations,
        private readonly Stage4cPolicy $policy,
        private readonly GatewayPaymentService $gateway,
        private readonly AuditService $audit,
    ) {}

    public function eligiblePayments(array $filters = []): LengthAwarePaginator
    {
        $query = PaymentTransaction::query()
            ->with(['customer:id,name,email,phone', 'organisation:id,name,reference'])
            ->where('status', 'succeeded')->whereNotNull('verified_at')->whereNotNull('received_at')
            ->orderByDesc('received_at')->orderByDesc('id');

        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn ($search) => $search->where('transaction_reference', 'like', $term)
                ->orWhere('provider_reference', 'like', $term)
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $term)->orWhere('email', 'like', $term))
                ->orWhereHas('organisation', fn ($organisation) => $organisation->where('name', 'like', $term)->orWhere('reference', 'like', $term)));
        }

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 20), 100)))
            ->through(function (PaymentTransaction $payment): array {
                return [
                    'id' => $payment->id,
                    'transaction_reference' => $payment->transaction_reference,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                    'payment_method' => $payment->payment_method,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'payer' => $this->payerArray($payment),
                    'received_at' => $payment->received_at,
                    'refundable_amount' => PaymentAllocationService::formatMinorUnits($this->refundableMinor($payment)),
                ];
            });
    }

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = RefundRequest::query()
            ->with(['payment:id,transaction_reference,provider,provider_reference,payment_method,amount,currency', 'customer:id,name', 'organisation:id,name', 'requester:id,name', 'approver:id,name'])
            ->latest('requested_at')->latest('id');
        if (filled($filters['status'] ?? null)) $query->where('status', $filters['status']);

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (RefundRequest $refund) => $this->summary($refund));
    }

    public function show(int $id): array
    {
        $refund = RefundRequest::query()
            ->with(['payment', 'customer:id,name,email,phone', 'organisation:id,name,reference', 'requester:id,name', 'approver:id,name', 'rejecter:id,name', 'confirmer:id,name', 'events'])
            ->findOrFail($id);

        return $this->summary($refund) + [
            'events' => $refund->events->map(fn (RefundEvent $event) => [
                'action' => $event->action,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'actor' => $event->actor_name_snapshot ?: $event->actor?->name,
                'reason' => $event->reason,
                'provider_reference' => $event->provider_reference,
                'metadata' => $event->metadata,
                'created_at' => $event->created_at,
            ])->values(),
            'provider_supports_refunds' => $this->policy->providerSupportsRefunds($refund->payment?->provider),
            'provider_submission_enabled' => $this->policy->providerRefundsEnabled(),
        ];
    }

    public function create(int $paymentId, string $amount, string $reason, string $reference, User $actor): RefundRequest
    {
        $minor = PaymentAllocationService::toMinorUnits($amount);
        if ($minor <= 0) throw new DomainException('A refund amount must be greater than zero.');
        if (trim($reason) === '') throw new DomainException('A reason is required to request a refund.');
        $this->assertReference($reference);

        return DB::transaction(function () use ($paymentId, $minor, $reason, $reference, $actor): RefundRequest {
            $payment = PaymentTransaction::query()->lockForUpdate()->findOrFail($paymentId);
            $this->assertRefundablePayment($payment);

            $existing = RefundRequest::query()->where('refund_reference', $reference)->first();
            if ($existing) {
                $this->assertSameOperation($existing, $payment, $minor, $reason, $actor);
                return $existing;
            }

            $refundable = $this->refundableMinor($payment);
            if ($minor > $refundable) {
                throw new DomainException('The refund exceeds the payment’s refundable balance. Allocated or credited funds must be released first.');
            }
            if ($minor < $refundable && ! $this->policy->partialRefundsAllowed()) {
                throw new DomainException('Partial refunds are not enabled by the current financial policy.');
            }

            $refund = RefundRequest::query()->create([
                'refund_reference' => $reference,
                'payment_transaction_id' => $payment->id,
                'customer_id' => $payment->customer_id,
                'organisation_id' => $payment->organisation_id,
                'amount' => PaymentAllocationService::formatMinorUnits($minor),
                'currency' => $payment->currency,
                'status' => RefundRequest::STATUS_REQUESTED,
                'reason' => trim($reason),
                'requested_by' => $actor->id,
                'requested_by_id_snapshot' => $actor->id,
                'requested_by_name_snapshot' => $actor->name,
                'requested_by_reference_snapshot' => 'user:'.$actor->id,
                'requested_at' => now(),
                'provider' => $payment->provider,
                'metadata' => ['payment_method' => $payment->payment_method],
            ]);
            $this->event($refund, 'requested', null, RefundRequest::STATUS_REQUESTED, $actor, trim($reason));
            $this->audit->log($actor, 'stage4c.refund.requested', 'Requested refund '.$refund->refund_reference.'.', $refund, null, [
                'amount' => $refund->amount, 'currency' => $refund->currency, 'payment_transaction_id' => $payment->id,
            ]);

            return $refund->fresh(['payment']);
        }, 3);
    }

    public function approve(int $id, User $actor, ?string $reason = null): RefundRequest
    {
        return DB::transaction(function () use ($id, $actor, $reason): RefundRequest {
            $refund = RefundRequest::query()->lockForUpdate()->findOrFail($id);
            if ($refund->status === RefundRequest::STATUS_APPROVED) return $refund;
            if ($refund->status !== RefundRequest::STATUS_REQUESTED) throw new DomainException('Only requested refunds can be approved.');
            if ($this->policy->separationOfDutiesRequired() && (int) $refund->requested_by === (int) $actor->id) {
                throw new DomainException('A different staff member must approve this refund.');
            }
            $refund->forceFill([
                'status' => RefundRequest::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approval_reason' => $reason ? trim($reason) : null,
            ])->save();
            $this->event($refund, 'approved', RefundRequest::STATUS_REQUESTED, RefundRequest::STATUS_APPROVED, $actor, $reason);
            $this->audit->log($actor, 'stage4c.refund.approved', 'Approved refund '.$refund->refund_reference.'.', $refund, null, ['amount' => $refund->amount]);
            return $refund->fresh(['payment']);
        }, 3);
    }

    public function reject(int $id, User $actor, string $reason): RefundRequest
    {
        if (trim($reason) === '') throw new DomainException('A rejection reason is required.');

        return DB::transaction(function () use ($id, $actor, $reason): RefundRequest {
            $refund = RefundRequest::query()->lockForUpdate()->findOrFail($id);
            if ($refund->status === RefundRequest::STATUS_REJECTED) return $refund;
            if (! in_array($refund->status, [RefundRequest::STATUS_REQUESTED, RefundRequest::STATUS_APPROVED], true)) {
                throw new DomainException('Only requested or approved refunds can be rejected.');
            }
            $from = $refund->status;
            $refund->forceFill([
                'status' => RefundRequest::STATUS_REJECTED,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ])->save();
            $this->event($refund, 'rejected', $from, RefundRequest::STATUS_REJECTED, $actor, trim($reason));
            $this->audit->log($actor, 'stage4c.refund.rejected', 'Rejected refund '.$refund->refund_reference.'.', $refund, null, ['reason' => trim($reason)]);
            return $refund->fresh(['payment']);
        }, 3);
    }

    public function submit(int $id, User $actor): RefundRequest
    {
        $refund = RefundRequest::query()->with('payment')->findOrFail($id);
        if ($refund->status === RefundRequest::STATUS_SUBMITTED) return $refund;
        if ($refund->status !== RefundRequest::STATUS_APPROVED) throw new DomainException('Only approved refunds can be submitted.');

        $payment = $refund->payment;
        $useProvider = $this->policy->providerRefundsEnabled() && $this->policy->providerSupportsRefunds($payment?->provider);

        if ($useProvider) {
            try {
                $result = $this->gateway->initiateRefund($payment, (string) $refund->amount, $refund->refund_reference);
            } catch (\Throwable $exception) {
                // D12: never mark confirmed on a provider failure; keep approved for a safe retry.
                $this->event($refund, 'provider_error', RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_APPROVED, $actor, $exception->getMessage(), null, ['mode' => 'provider']);
                throw new DomainException('The payment provider could not accept the refund right now. The refund remains approved and can be retried safely.');
            }

            return DB::transaction(function () use ($id, $actor, $result): RefundRequest {
                $locked = RefundRequest::query()->lockForUpdate()->findOrFail($id);
                if ($locked->status !== RefundRequest::STATUS_APPROVED) return $locked;
                $locked->forceFill([
                    'status' => RefundRequest::STATUS_SUBMITTED,
                    'provider' => 'paystack',
                    'provider_refund_reference' => $result['provider_refund_reference'],
                    'submitted_at' => now(),
                    'metadata' => array_merge((array) $locked->metadata, ['mode' => 'provider', 'provider_status' => $result['provider_status']]),
                ])->save();
                $this->event($locked, 'submitted', RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_SUBMITTED, $actor, null, $result['provider_refund_reference'], ['mode' => 'provider']);
                $this->audit->log($actor, 'stage4c.refund.submitted', 'Submitted refund '.$locked->refund_reference.' to the provider.', $locked, null, ['provider' => 'paystack']);
                return $locked->fresh(['payment']);
            }, 3);
        }

        return DB::transaction(function () use ($id, $actor): RefundRequest {
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($id);
            if ($locked->status === RefundRequest::STATUS_SUBMITTED) return $locked;
            if ($locked->status !== RefundRequest::STATUS_APPROVED) throw new DomainException('Only approved refunds can be submitted.');
            $locked->forceFill([
                'status' => RefundRequest::STATUS_SUBMITTED,
                'provider' => $locked->payment?->provider ?: 'manual',
                'submitted_at' => now(),
                'metadata' => array_merge((array) $locked->metadata, ['mode' => 'manual']),
            ])->save();
            $this->event($locked, 'submitted', RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_SUBMITTED, $actor, 'Manual refund instruction recorded.', null, ['mode' => 'manual']);
            $this->audit->log($actor, 'stage4c.refund.submitted', 'Recorded a manual refund instruction for '.$locked->refund_reference.'.', $locked, null, ['mode' => 'manual']);
            return $locked->fresh(['payment']);
        }, 3);
    }

    public function confirm(int $id, User $actor, ?string $providerReference, ?string $evidence): RefundRequest
    {
        return DB::transaction(function () use ($id, $actor, $providerReference, $evidence): RefundRequest {
            $refund = RefundRequest::query()->lockForUpdate()->with('payment')->findOrFail($id);
            if ($refund->status === RefundRequest::STATUS_CONFIRMED) return $refund;
            if ($refund->status !== RefundRequest::STATUS_SUBMITTED) throw new DomainException('Only submitted refunds can be confirmed.');
            if ($this->policy->separationOfDutiesRequired() && (int) $refund->requested_by === (int) $actor->id) {
                throw new DomainException('A different staff member must confirm this refund.');
            }

            $mode = $refund->metadata['mode'] ?? 'manual';
            $reference = $providerReference;
            if ($mode === 'provider' && $this->policy->providerRefundsEnabled()) {
                $verification = $this->gateway->verifyRefund($refund->payment, (string) $refund->provider_refund_reference);
                $expectedMinor = PaymentAllocationService::toMinorUnits((string) $refund->amount);
                if (($verification['provider_status'] ?? null) !== 'processed'
                    || (int) ($verification['amount_minor'] ?? -1) !== $expectedMinor
                    || strtoupper((string) ($verification['currency'] ?? '')) !== strtoupper((string) $refund->currency)) {
                    throw new DomainException('The provider has not confirmed the exact refund amount and currency.');
                }
                $reference = $refund->provider_refund_reference;
            } else {
                if (trim((string) $evidence) === '') throw new DomainException('Manual refund confirmation requires recorded evidence.');
                if (trim((string) $reference) === '') throw new DomainException('Manual refund confirmation requires a payment reference.');
            }

            $refund->forceFill([
                'status' => RefundRequest::STATUS_CONFIRMED,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'verification_reference' => $reference,
                'verification_evidence' => $evidence,
            ])->save();
            $this->event($refund, 'confirmed', RefundRequest::STATUS_SUBMITTED, RefundRequest::STATUS_CONFIRMED, $actor, null, $reference, ['mode' => $mode]);

            if ($this->policy->markTransactionRefunded() && $refund->payment) {
                $remaining = $this->refundableMinor($refund->payment);
                if ($remaining === 0) $refund->payment->forceFill(['status' => 'refunded'])->save();
            }

            $this->audit->log($actor, 'stage4c.refund.confirmed', 'Confirmed refund '.$refund->refund_reference.'.', $refund, null, [
                'amount' => $refund->amount, 'currency' => $refund->currency, 'mode' => $mode, 'reference' => $reference,
            ]);
            return $refund->fresh(['payment']);
        }, 3);
    }

    public function fail(int $id, User $actor, string $reason): RefundRequest
    {
        if (trim($reason) === '') throw new DomainException('A failure reason is required.');

        return DB::transaction(function () use ($id, $actor, $reason): RefundRequest {
            $refund = RefundRequest::query()->lockForUpdate()->findOrFail($id);
            if ($refund->status === RefundRequest::STATUS_FAILED) return $refund;
            if (! in_array($refund->status, [RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_SUBMITTED], true)) {
                throw new DomainException('Only approved or submitted refunds can be marked failed.');
            }
            $from = $refund->status;
            $refund->forceFill([
                'status' => RefundRequest::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => trim($reason),
            ])->save();
            $this->event($refund, 'failed', $from, RefundRequest::STATUS_FAILED, $actor, trim($reason));
            $this->audit->log($actor, 'stage4c.refund.failed', 'Marked refund '.$refund->refund_reference.' as failed.', $refund, null, ['reason' => trim($reason)]);
            return $refund->fresh(['payment']);
        }, 3);
    }

    /**
     * Refundable = receipt amount − allocations − credit designations − all
     * refunds that are still live (requested, approved, submitted, confirmed).
     */
    public function refundableMinor(PaymentTransaction $payment): int
    {
        $committed = RefundRequest::query()
            ->where('payment_transaction_id', $payment->id)
            ->whereIn('status', [
                RefundRequest::STATUS_REQUESTED, RefundRequest::STATUS_APPROVED,
                RefundRequest::STATUS_SUBMITTED, RefundRequest::STATUS_CONFIRMED,
            ])->sum('amount');

        return max(0,
            PaymentAllocationService::toMinorUnits((string) $payment->amount)
            - PaymentAllocationService::toMinorUnits((string) ($committed ?: '0'))
            - $this->allocations->paymentAllocatedMinorForPayment($payment->id)
            - $this->allocations->creditDesignatedMinorForPayment($payment->id)
        );
    }

    private function assertRefundablePayment(PaymentTransaction $payment): void
    {
        if ($payment->status !== 'succeeded' || ! $payment->verified_at || ! $payment->received_at) {
            throw new DomainException('Only verified and received successful payments can be refunded.');
        }
        if (($payment->customer_id !== null) === ($payment->organisation_id !== null)) {
            throw new DomainException('The payment must have exactly one identified payer.');
        }
    }

    private function summary(RefundRequest $refund): array
    {
        return [
            'id' => $refund->id,
            'refund_reference' => $refund->refund_reference,
            'status' => $refund->status,
            'amount' => $refund->amount,
            'currency' => $refund->currency,
            'reason' => $refund->reason,
            'payer' => $refund->customer_id !== null
                ? ['type' => 'customer', 'id' => $refund->customer?->id, 'name' => $refund->customer?->name]
                : ['type' => 'organisation', 'id' => $refund->organisation?->id, 'name' => $refund->organisation?->name],
            'payment' => $refund->payment ? [
                'id' => $refund->payment->id,
                'transaction_reference' => $refund->payment->transaction_reference,
                'provider' => $refund->payment->provider,
                'provider_reference' => $refund->payment->provider_reference,
                'payment_method' => $refund->payment->payment_method,
                'amount' => $refund->payment->amount,
                'currency' => $refund->payment->currency,
            ] : null,
            'provider' => $refund->provider,
            'provider_refund_reference' => $refund->provider_refund_reference,
            'verification_reference' => $refund->verification_reference,
            'requested_by' => $refund->requested_by_name_snapshot ?: $refund->requester?->name,
            'requested_at' => $refund->requested_at,
            'approved_by' => $refund->approver?->name,
            'approved_at' => $refund->approved_at,
            'rejected_at' => $refund->rejected_at,
            'rejection_reason' => $refund->rejection_reason,
            'submitted_at' => $refund->submitted_at,
            'confirmed_at' => $refund->confirmed_at,
            'failed_at' => $refund->failed_at,
            'failure_reason' => $refund->failure_reason,
            'metadata' => $refund->metadata,
            'created_at' => $refund->created_at,
        ];
    }

    private function payerArray(PaymentTransaction $payment): array
    {
        return $payment->organisation_id !== null
            ? ['type' => 'organisation', 'id' => $payment->organisation?->id, 'name' => $payment->organisation?->name]
            : ['type' => 'customer', 'id' => $payment->customer?->id, 'name' => $payment->customer?->name];
    }

    private function event(RefundRequest $refund, string $action, ?string $from, ?string $to, User $actor, ?string $reason = null, ?string $providerReference = null, ?array $metadata = null): void
    {
        RefundEvent::query()->create([
            'refund_request_id' => $refund->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor->id,
            'actor_user_id_snapshot' => $actor->id,
            'actor_name_snapshot' => $actor->name,
            'actor_reference_snapshot' => 'user:'.$actor->id,
            'reason' => $reason,
            'provider_reference' => $providerReference,
            'metadata' => $metadata,
        ]);
    }

    private function assertSameOperation(RefundRequest $existing, PaymentTransaction $payment, int $minor, string $reason, User $actor): void
    {
        if ((int) $existing->payment_transaction_id !== (int) $payment->id
            || PaymentAllocationService::toMinorUnits((string) $existing->amount) !== $minor
            || trim((string) $existing->reason) !== trim($reason)
            || (int) $existing->requested_by !== (int) $actor->id) {
            throw new DomainException('This refund reference was already used for a different refund request.');
        }
    }

    private function assertReference(string $reference): void
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $reference)) {
            throw new DomainException('The refund reference is invalid.');
        }
    }
}
