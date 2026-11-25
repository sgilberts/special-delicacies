<?php

namespace App\Events;

use App\Models\PaymentTransaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FinancialPaymentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public PaymentTransaction $transaction) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('financials')];
    }

    public function broadcastAs(): string
    {
        return 'payment.updated';
    }

    public function broadcastWith(): array
    {
        $transaction = $this->transaction->loadMissing('order:id,order_number,order_type,status');

        return ['transaction' => [
            'id' => $transaction->id,
            'order_id' => $transaction->order_id,
            'order_number' => $transaction->order?->order_number,
            'order_type' => $transaction->order?->order_type,
            'transaction_reference' => $transaction->transaction_reference,
            'provider' => $transaction->provider,
            'payment_method' => $transaction->payment_method,
            'amount' => (string) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status,
            'received_at' => $transaction->received_at?->toISOString(),
        ]];
    }
}
