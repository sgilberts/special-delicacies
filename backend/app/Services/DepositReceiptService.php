<?php

namespace App\Services;

use App\Models\PaymentReceiptIntent;
use App\Models\PaymentTransaction;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Shared completion path for every deposit/advance receipt, whichever
 * origination workflow produced it. A receipt may only become credit-eligible
 * through this service, which requires an already-trusted payment state and
 * writes the single canonical receipt-intent classification.
 */
class DepositReceiptService
{
    /**
     * Mark a pending standalone receipt as received and classify it.
     *
     * @param  array{name: string, reference: string, reason: string, customer_id?: int|null}  $actorSnapshot
     */
    public function complete(PaymentTransaction $transaction, string $intent, ?User $actor, array $actorSnapshot, ?string $providerReference = null): PaymentTransaction
    {
        if (! in_array($intent, ['deposit', 'advance'], true)) {
            throw new DomainException('Choose deposit or advance payment intent.');
        }

        return DB::transaction(function () use ($transaction, $intent, $actor, $actorSnapshot, $providerReference): PaymentTransaction {
            $locked = PaymentTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->order_id !== null || $locked->payment_purpose !== 'invoice') {
                throw new DomainException('Only standalone invoice-purpose receipts can be completed as deposits.');
            }

            if ($locked->status === 'succeeded') {
                // Idempotent replay: never write a second receipt or intent.
                $this->ensureIntent($locked, $intent, $actor, $actorSnapshot);
                return $locked->fresh(['receiptIntent']);
            }
            if ($locked->status !== 'pending') {
                throw new DomainException('Only a pending deposit can be completed.');
            }

            $locked->forceFill([
                'status' => 'succeeded',
                'provider_reference' => $providerReference ?: $locked->provider_reference,
                'verified_at' => now(),
                'received_at' => now(),
                'reporting_date' => now()->toDateString(),
            ])->save();

            $this->ensureIntent($locked, $intent, $actor, $actorSnapshot);
            return $locked->fresh(['receiptIntent']);
        }, 3);
    }

    /** Close a pending receipt as failed or cancelled without ever crediting it. */
    public function fail(PaymentTransaction $transaction, string $status): PaymentTransaction
    {
        if (! in_array($status, ['failed', 'cancelled'], true)) {
            throw new DomainException('Unsupported deposit failure state.');
        }

        return DB::transaction(function () use ($transaction, $status): PaymentTransaction {
            $locked = PaymentTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->status === 'succeeded') {
                return $locked;
            }
            if ($locked->status !== $status) {
                $locked->forceFill([
                    'status' => $status,
                    'verified_at' => now(),
                    'reporting_date' => now()->toDateString(),
                ])->save();
            }
            return $locked->fresh();
        }, 3);
    }

    private function ensureIntent(PaymentTransaction $transaction, string $intent, ?User $actor, array $snapshot): PaymentReceiptIntent
    {
        $existing = PaymentReceiptIntent::query()->where('payment_transaction_id', $transaction->id)->first();
        if ($existing) {
            return $existing;
        }

        return PaymentReceiptIntent::query()->create([
            'payment_transaction_id' => $transaction->id,
            'intent' => $intent,
            'operation_reference' => 'DEP-INTENT-'.$transaction->id,
            'reason' => trim((string) ($snapshot['reason'] ?? 'Deposit receipt confirmed.')),
            'actor_user_id' => $actor?->id,
            'actor_user_id_snapshot' => $actor?->id ?? ($snapshot['customer_id'] ?? null),
            'actor_name_snapshot' => $snapshot['name'] ?? null,
            'actor_reference_snapshot' => $snapshot['reference'] ?? null,
            'designated_at' => now(),
        ]);
    }
}
