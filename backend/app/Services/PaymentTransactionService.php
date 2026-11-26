<?php

namespace App\Services;

use App\Events\FinancialPaymentUpdated;
use App\Events\OrderPaymentStatusUpdated;
use App\Events\OrderTrackingUpdated;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentTransactionService
{
    public function recordPending(Order $order): PaymentTransaction
    {
        $reference = $order->payment_reference ?: 'CASH-'.$order->order_number;
        return PaymentTransaction::firstOrCreate(
            ['transaction_reference' => $reference],
            [
                'order_id' => $order->id,
                'provider' => $order->paymentGateway?->provider,
                'payment_method' => $order->payment_method,
                'amount' => $order->total,
                'currency' => 'GHS',
                'status' => 'pending',
                'provider_reference' => $order->payment_reference,
                'reporting_date' => $order->created_at?->toDateString() ?? now()->toDateString(),
            ]
        );
    }

    public function recordGatewaySuccess(Order $order, ?string $providerReference = null): PaymentTransaction
    {
        [$transaction, $changed] = DB::transaction(function () use ($order, $providerReference) {
            $order = Order::query()->with('paymentGateway')->lockForUpdate()->findOrFail($order->id);
            $reference = $order->payment_reference;
            $transaction = PaymentTransaction::query()->where('transaction_reference', $reference)->lockForUpdate()->first();
            if ($transaction && (int) $transaction->order_id !== (int) $order->id) {
                throw new \DomainException('The payment transaction is associated with a different order.');
            }
            if ($transaction && ($transaction->currency !== 'GHS' || (int) round((float) $transaction->amount * 100) !== (int) round((float) $order->total * 100))) {
                throw new \DomainException('The payment transaction amount or currency does not match the order.');
            }
            if (! $transaction) {
                $transaction = PaymentTransaction::create([
                    'order_id' => $order->id,
                    'transaction_reference' => $reference,
                    'provider' => $order->paymentGateway?->provider,
                    'payment_method' => 'gateway',
                    'amount' => $order->total,
                    'currency' => 'GHS',
                    'status' => 'pending',
                    'reporting_date' => $order->created_at?->toDateString() ?? now()->toDateString(),
                ]);
            }
            $changed = false;
            if ($transaction->status !== 'succeeded') {
                $transaction->forceFill([
                    'status' => 'succeeded',
                    'provider_reference' => $providerReference ?: $reference,
                    'verified_at' => now(),
                    'received_at' => now(),
                    'reporting_date' => now()->toDateString(),
                ])->save();
                $changed = true;
            }
            // Repair legacy/inconsistent rows even when the transaction was already
            // successful. This is the source of the reported Pending-after-payment bug.
            if ($order->payment_status !== Order::PAYMENT_STATUS_PAID) {
                $order->forceFill(['payment_status' => Order::PAYMENT_STATUS_PAID])->save();
                $changed = true;
            }
            if ($order->order_type === Order::TYPE_DELIVERY
                && $order->delivery_fee_payment_method === 'online'
                && (int) round((float) $order->delivery_fee * 100) > 0
                && $order->delivery_fee_payment_status !== Order::DELIVERY_FEE_PAID) {
                $order->forceFill(['delivery_fee_payment_status' => Order::DELIVERY_FEE_PAID])->save();
                $changed = true;
            }
            return [$transaction->fresh(), $changed];
        });

        if ($changed) {
            $freshOrder = $transaction->order()->firstOrFail();
            event(new FinancialPaymentUpdated($transaction));
            event(new OrderTrackingUpdated($freshOrder));
            event(new OrderPaymentStatusUpdated($freshOrder));
        }
        return $transaction;
    }

    public function recordGatewayStatus(Order $order, string $status, ?string $providerReference = null): ?PaymentTransaction
    {
        if (! in_array($status, ['failed', 'cancelled'], true)) return null;
        [$transaction, $changed] = DB::transaction(function () use ($order, $status, $providerReference) {
            $order = Order::query()->with('paymentGateway')->lockForUpdate()->findOrFail($order->id);
            $transaction = PaymentTransaction::query()->where('transaction_reference', $order->payment_reference)->lockForUpdate()->first();
            if ($transaction && (int) $transaction->order_id !== (int) $order->id) {
                throw new \DomainException('The payment transaction is associated with a different order.');
            }
            if ($transaction?->status === 'succeeded') {
                $changed = false;
                if ($order->payment_status !== Order::PAYMENT_STATUS_PAID) {
                    $order->forceFill(['payment_status' => Order::PAYMENT_STATUS_PAID])->save();
                    $changed = true;
                }
                return [$transaction->fresh(), $changed];
            }
            if (! $transaction && $order->payment_status === Order::PAYMENT_STATUS_PAID) return [null, false];
            $changed = false;
            if (! $transaction) {
                $transaction = PaymentTransaction::create([
                    'order_id' => $order->id,
                    'transaction_reference' => $order->payment_reference,
                    'provider' => $order->paymentGateway?->provider,
                    'payment_method' => 'gateway',
                    'amount' => $order->total,
                    'currency' => 'GHS',
                    'status' => $status,
                    'provider_reference' => $providerReference,
                    'verified_at' => now(),
                    'reporting_date' => now()->toDateString(),
                ]);
                $changed = true;
            } elseif ($transaction->status !== $status) {
                $changed = true;
                $transaction->forceFill([
                    'status' => $status,
                    'provider_reference' => $providerReference ?: $transaction->provider_reference,
                    'verified_at' => now(),
                    'reporting_date' => now()->toDateString(),
                ])->save();
            }
            return [$transaction->fresh(), $changed];
        });
        if ($transaction && $changed) {
            $freshOrder = $transaction->order()->firstOrFail();
            event(new FinancialPaymentUpdated($transaction));
            event(new OrderTrackingUpdated($freshOrder));
            event(new OrderPaymentStatusUpdated($freshOrder));
        }
        return $transaction;
    }

    public function confirmCash(Order $order, User $user): PaymentTransaction
    {
        $transaction = DB::transaction(function () use ($order, $user) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $reference = 'CASH-'.$lockedOrder->order_number;
            $transaction = PaymentTransaction::query()->where('order_id', $lockedOrder->id)->lockForUpdate()->first();
            if ($transaction?->status === 'succeeded') return $transaction;

            $transaction ??= PaymentTransaction::firstOrCreate(
                ['transaction_reference' => $reference],
                [
                    'order_id' => $lockedOrder->id,
                    'payment_method' => 'cash',
                    'amount' => $lockedOrder->total,
                    'currency' => 'GHS',
                    'status' => 'pending',
                    'reporting_date' => $lockedOrder->created_at?->toDateString() ?? now()->toDateString(),
                ]
            );
            $transaction->forceFill([
                'status' => 'succeeded',
                'verified_at' => now(),
                'received_at' => now(),
                'reporting_date' => now()->toDateString(),
                'confirmed_by' => $user->id,
            ])->save();
            $lockedOrder->forceFill(['payment_status' => Order::PAYMENT_STATUS_PAID])->save();
            return $transaction->fresh();
        });

        event(new FinancialPaymentUpdated($transaction));
        event(new OrderTrackingUpdated($transaction->order()->firstOrFail()));
        return $transaction;
    }

    public function confirmDeliveryFeeCash(Order $order, User $user): PaymentTransaction
    {
        $transaction = DB::transaction(function () use ($order, $user) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->order_type !== Order::TYPE_DELIVERY
                || $lockedOrder->delivery_fee_payment_method !== 'cash_on_delivery'
                || $lockedOrder->payment_status !== Order::PAYMENT_STATUS_PAID
                || $lockedOrder->status !== Order::STATUS_DELIVERED) {
                throw new \DomainException('The food must be paid online and the delivery must be marked delivered before confirming its cash delivery fee.');
            }

            $reference = 'DELIVERY-FEE-CASH-'.$lockedOrder->order_number;
            $transaction = PaymentTransaction::query()->where('transaction_reference', $reference)->lockForUpdate()->first();
            if ($transaction?->status === 'succeeded') return $transaction;

            $transaction ??= PaymentTransaction::create([
                'order_id' => $lockedOrder->id,
                'transaction_reference' => $reference,
                'payment_method' => 'cash',
                'amount' => $lockedOrder->delivery_fee,
                'currency' => 'GHS',
                'status' => 'pending',
                'reporting_date' => now()->toDateString(),
            ]);
            $transaction->forceFill([
                'status' => 'succeeded',
                'verified_at' => now(),
                'received_at' => now(),
                'reporting_date' => now()->toDateString(),
                'confirmed_by' => $user->id,
            ])->save();
            $lockedOrder->forceFill([
                'delivery_fee_payment_status' => Order::DELIVERY_FEE_PAID,
                'delivery_fee_confirmed_at' => now(),
                'delivery_fee_confirmed_by' => $user->id,
            ])->save();

            return $transaction->fresh();
        });

        event(new FinancialPaymentUpdated($transaction));
        event(new OrderTrackingUpdated($transaction->order()->firstOrFail()));
        return $transaction;
    }

    public function cancelPending(Order $order): void
    {
        $transaction = PaymentTransaction::query()->where('order_id', $order->id)->where('status', 'pending')->first();
        if (! $transaction) return;
        $transaction->forceFill(['status' => 'cancelled', 'verified_at' => now(), 'reporting_date' => now()->toDateString()])->save();
        event(new FinancialPaymentUpdated($transaction->fresh()));
    }
}
