<?php

namespace App\Services;

use App\Exceptions\ProviderRefundRejected;
use App\Exceptions\ProviderRefundUnavailable;
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
            'provider_outcome' => $refund->metadata['provider_outcome'] ?? null,
            'reconciliation_required' => $refund->status === RefundRequest::STATUS_SUBMITTING,
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

    /**
     * Submit an approved refund.
     *
     * Provider submissions are deliberately split in two database phases around
     * the provider call:
     *
     *   1. Persist `submitting` (the intent, with a stable provider reference)
     *      and commit BEFORE the provider is called. If the process dies after
     *      this point the intent is durable.
     *   2. Call the provider outside any database transaction, then persist the
     *      result.
     *
     * If the provider accepts the refund but the second write fails, the refund
     * stays `submitting` — an unresolved outcome. It is never silently rolled
     * back to `approved` (which would allow a duplicate refund) and it is never
     * marked confirmed, failed or rejected. Reconciliation resolves it.
     */
    public function submit(int $id, User $actor): RefundRequest
    {
        $refund = DB::transaction(function () use ($id, $actor): RefundRequest {
            $refund = RefundRequest::query()->lockForUpdate()->with('payment')->findOrFail($id);
            if ($refund->status === RefundRequest::STATUS_SUBMITTED) return $refund;
            if ($refund->status === RefundRequest::STATUS_SUBMITTING) {
                throw new DomainException('The provider outcome for this refund is unconfirmed. Reconcile it before submitting again.');
            }
            if ($refund->status !== RefundRequest::STATUS_APPROVED) throw new DomainException('Only approved refunds can be submitted.');

            $payment = $refund->payment;
            if (! ($this->policy->providerRefundsEnabled() && $this->policy->providerSupportsRefunds($payment?->provider))) {
                $refund->forceFill([
                    'status' => RefundRequest::STATUS_SUBMITTED,
                    'provider' => $payment?->provider ?: 'manual',
                    'submitted_at' => now(),
                    'metadata' => array_merge((array) $refund->metadata, ['mode' => 'manual']),
                ])->save();
                $this->event($refund, 'submitted', RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_SUBMITTED, $actor, 'Manual refund instruction recorded.', null, ['mode' => 'manual']);
                $this->audit->log($actor, 'stage4c.refund.submitted', 'Recorded a manual refund instruction for '.$refund->refund_reference.'.', $refund, null, ['mode' => 'manual']);
                return $refund->fresh(['payment']);
            }

            // Phase 1: durable submission intent, committed before the provider call.
            $refund->forceFill([
                'status' => RefundRequest::STATUS_SUBMITTING,
                'provider' => $payment->provider,
                'submitted_at' => now(),
                'metadata' => array_merge((array) $refund->metadata, [
                    'mode' => 'provider',
                    'provider_outcome' => 'unknown',
                    'provider_idempotency_key' => $this->providerIdempotencyKey($refund->refund_reference),
                ]),
            ])->save();
            $this->event($refund, 'submitting', RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_SUBMITTING, $actor, 'Provider refund instruction dispatched; outcome not yet recorded.', null, ['mode' => 'provider']);

            return $refund;
        }, 1);

        // Manual submissions and idempotent replays are already complete.
        if ($refund->status !== RefundRequest::STATUS_SUBMITTING || ($refund->metadata['mode'] ?? null) !== 'provider') {
            return $refund;
        }

        $key = $this->providerIdempotencyKey($refund->refund_reference);
        try {
            $result = $this->gateway->initiateRefund($refund->payment, (string) $refund->amount, $refund->refund_reference, $key);
        } catch (ProviderRefundRejected $exception) {
            // Definitive refusal: no money moved, so returning to `approved` is safe.
            try {
                $this->markProviderRejected($id, $actor, $exception->getPrevious()?->getMessage() ?? $exception->getMessage());
            } catch (\Throwable) {
                // The state write failed, so the refund stays `submitting`.
                throw new DomainException('The payment provider rejected the refund, but the refund could not be returned to approved. Reconcile it before submitting again.');
            }
            throw new DomainException('The payment provider rejected the refund. The refund remains approved and can be corrected and retried.');
        } catch (\Throwable $exception) {
            // Unknown outcome: keep `submitting` and require reconciliation.
            $this->recordProviderErrorQuietly($id, $actor, $exception->getPrevious()?->getMessage() ?? $exception->getMessage());
            throw new DomainException('The provider did not confirm whether it accepted this refund. The refund is held as unresolved and must be reconciled before any further submission.');
        }

        try {
            return $this->recordProviderAcceptance($id, $actor, $result, 'submitted');
        } catch (\Throwable $exception) {
            // The provider accepted the refund but the result could not be
            // written: the refund stays `submitting` and must be reconciled.
            $this->recordProviderErrorQuietly($id, $actor, 'Provider accepted the refund but the result could not be recorded: '.$exception->getMessage());
            throw new DomainException('The provider accepted this refund but the result could not be recorded. The refund is held as unresolved and must be reconciled; do not submit it again.');
        }
    }

    /**
     * Resolve a refund whose provider outcome was never persisted.
     *
     * The provider is queried by the stable refund reference that was sent with
     * the original instruction. When the refund exists it is recorded as
     * submitted (confirmation stays a separate, independent step). When the
     * provider is reachable and has no such refund, the operator may explicitly
     * confirm that (`confirm_absent`) to return it to `approved`. When the
     * provider cannot be reached the refund stays unresolved.
     */
    public function reconcile(int $id, User $actor, bool $confirmAbsent = false): RefundRequest
    {
        $refund = RefundRequest::query()->with('payment')->findOrFail($id);
        if ($refund->status !== RefundRequest::STATUS_SUBMITTING) {
            throw new DomainException('Only a refund with an unconfirmed provider outcome can be reconciled.');
        }
        if (($refund->metadata['mode'] ?? null) !== 'provider') {
            throw new DomainException('This refund was submitted manually and has no provider outcome to reconcile.');
        }

        $key = $refund->metadata['provider_idempotency_key'] ?? $this->providerIdempotencyKey($refund->refund_reference);
        try {
            $found = $this->gateway->findRefundByReference($refund->payment, $key);
        } catch (\Throwable $exception) {
            $this->recordProviderErrorQuietly($id, $actor, 'Reconciliation lookup failed: '.$exception->getMessage());
            throw new DomainException('The provider could not be checked. The refund remains unresolved; do not resubmit it.');
        }

        if ($found !== null) {
            $expected = PaymentAllocationService::toMinorUnits((string) $refund->amount);
            if ((int) ($found['amount_minor'] ?? -1) !== $expected
                || strtoupper((string) ($found['currency'] ?? '')) !== strtoupper((string) $refund->currency)) {
                $this->event($refund, 'reconciliation_mismatch', RefundRequest::STATUS_SUBMITTING, RefundRequest::STATUS_SUBMITTING, $actor, 'A provider refund exists for this reference but its amount or currency does not match.', $found['provider_refund_reference'] ?? null, ['provider_outcome' => 'unknown']);
                throw new DomainException('A provider refund with this reference exists but its amount or currency does not match. It remains unresolved for manual investigation.');
            }

            return $this->recordProviderAcceptance($id, $actor, $found, 'reconciled');
        }

        if (! $confirmAbsent) {
            throw new DomainException('The provider has no refund for this reference. Once you have confirmed that with the provider, reconcile again with confirm_absent to return the refund to approved.');
        }

        return DB::transaction(function () use ($id, $actor): RefundRequest {
            $refund = RefundRequest::query()->lockForUpdate()->with('payment')->findOrFail($id);
            if ($refund->status !== RefundRequest::STATUS_SUBMITTING) return $refund;
            $refund->forceFill([
                'status' => RefundRequest::STATUS_APPROVED,
                'submitted_at' => null,
                'metadata' => array_merge((array) $refund->metadata, ['provider_outcome' => 'absent_confirmed']),
            ])->save();
            $this->event($refund, 'reconciled_absent', RefundRequest::STATUS_SUBMITTING, RefundRequest::STATUS_APPROVED, $actor, 'Provider confirmed no refund exists for this reference; the refund returned to approved.', null, ['provider_outcome' => 'absent_confirmed']);
            $this->audit->log($actor, 'stage4c.refund.reconciled_absent', 'Reconciled refund '.$refund->refund_reference.' as not present at the provider.', $refund);
            return $refund->fresh(['payment']);
        }, 1);
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
                if (! $refund->provider_refund_reference) {
                    throw new DomainException('The provider refund reference is not recorded. Reconcile this refund before confirming it.');
                }
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
                // An unresolved provider submission still reserves the funds.
                RefundRequest::STATUS_SUBMITTING,
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
            'provider_outcome' => $refund->metadata['provider_outcome'] ?? null,
            'reconciliation_required' => $refund->status === RefundRequest::STATUS_SUBMITTING,
            'created_at' => $refund->created_at,
        ];
    }

    private function payerArray(PaymentTransaction $payment): array
    {
        return $payment->organisation_id !== null
            ? ['type' => 'organisation', 'id' => $payment->organisation?->id, 'name' => $payment->organisation?->name]
            : ['type' => 'customer', 'id' => $payment->customer?->id, 'name' => $payment->customer?->name];
    }

    /**
     * Stable provider-side reference derived from the refund reference. It is
     * sent as Paystack's refund `reference`, so a repeated instruction with the
     * same value cannot create a second refund and the refund can be looked up
     * again by that key.
     */
    public function providerIdempotencyKey(string $refundReference): string
    {
        return 'sdr'.substr(hash('sha256', 'stage4c:'.$refundReference), 0, 32);
    }

    /**
     * Persist a provider acceptance (fresh submission or reconciliation). If
     * this write fails, the refund stays `submitting` — never `approved`.
     */
    private function recordProviderAcceptance(int $id, User $actor, array $result, string $action): RefundRequest
    {
        return DB::transaction(function () use ($id, $actor, $result, $action): RefundRequest {
            $refund = RefundRequest::query()->lockForUpdate()->with('payment')->findOrFail($id);
            if ($refund->status !== RefundRequest::STATUS_SUBMITTING) return $refund;

            $refund->forceFill([
                'status' => RefundRequest::STATUS_SUBMITTED,
                'provider_refund_reference' => $result['provider_refund_reference'] ?? null,
                'metadata' => array_merge((array) $refund->metadata, [
                    'mode' => 'provider',
                    'provider_outcome' => 'accepted',
                    'provider_status' => $result['provider_status'] ?? null,
                ]),
            ])->save();
            $this->event($refund, $action, RefundRequest::STATUS_SUBMITTING, RefundRequest::STATUS_SUBMITTED, $actor, $action === 'reconciled' ? 'Provider refund found by reference; outcome recorded.' : null, $result['provider_refund_reference'] ?? null, ['mode' => 'provider', 'provider_status' => $result['provider_status'] ?? null]);
            $this->audit->log($actor, 'stage4c.refund.'.$action, ucfirst($action).' refund '.$refund->refund_reference.' with the provider.', $refund, null, ['provider_refund_reference' => $result['provider_refund_reference'] ?? null]);

            return $refund->fresh(['payment']);
        }, 1);
    }

    /** A definitive provider refusal: safe to return to `approved`. */
    private function markProviderRejected(int $id, User $actor, string $message): void
    {
        DB::transaction(function () use ($id, $actor, $message): void {
            $refund = RefundRequest::query()->lockForUpdate()->findOrFail($id);
            if ($refund->status !== RefundRequest::STATUS_SUBMITTING) return;
            $refund->forceFill([
                'status' => RefundRequest::STATUS_APPROVED,
                'submitted_at' => null,
                'metadata' => array_merge((array) $refund->metadata, ['provider_outcome' => 'rejected']),
            ])->save();
            $this->event($refund, 'provider_error', RefundRequest::STATUS_SUBMITTING, RefundRequest::STATUS_APPROVED, $actor, $message ?? 'Provider rejected the refund.', null, ['mode' => 'provider', 'provider_outcome' => 'rejected']);
        }, 1);
    }

    /**
     * Best-effort variant for a path that is already failing: the refund state
     * (not the audit row) is what protects the money, so an event-write failure
     * must not mask the original outcome.
     */
    private function recordProviderErrorQuietly(int $id, User $actor, string $message): void
    {
        try {
            $this->recordProviderError($id, $actor, $message);
        } catch (\Throwable) {
            // The refund is already held in `submitting`; nothing else to do.
        }
    }

    /**
     * Record a provider problem without changing the outcome. For an unknown
     * outcome the refund deliberately stays `submitting`.
     */
    private function recordProviderError(int $id, User $actor, string $message, string $outcome = 'unknown'): void
    {
        $refund = RefundRequest::query()->find($id);
        if (! $refund) return;
        $this->event($refund, 'provider_error', $refund->status, $refund->status, $actor, $message, null, ['mode' => 'provider', 'provider_outcome' => $outcome]);
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
