<?php

namespace App\Services;

use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use DomainException;
use Illuminate\Support\Facades\Http;

/**
 * Provider communication for refunds. It deliberately does not change refund
 * status: RefundService owns the lifecycle and only accepts verified provider
 * evidence. Paystack is the only provider with an implemented automated refund
 * contract in the existing integration; Hubtel and Kora refunds must be
 * recorded as manual outcomes until their contracts are authorised.
 */
class RefundGatewayService
{
    public function __construct(private readonly GatewayPaymentService $payments) {}

    public function gatewayForPayment(PaymentTransaction $payment): ?PaymentGateway
    {
        if ($payment->order_id !== null) {
            $order = $payment->order()->first();
            if ($order?->payment_gateway_id) {
                $gateway = PaymentGateway::query()->find($order->payment_gateway_id);
                if ($gateway && $this->hasCredentials($gateway)) return $gateway;
            }
        }
        if (filled($payment->provider)) {
            $gateway = PaymentGateway::query()->where('provider', $payment->provider)
                ->whereNotNull('secret_key')->orderByDesc('is_default')->first();
            if ($gateway && $this->hasCredentials($gateway)) return $gateway;
        }
        return null;
    }

    public function supportsAutomatedRefunds(?PaymentGateway $gateway): bool
    {
        return $gateway?->provider === 'paystack';
    }

    /**
     * Submit a refund to the provider. Returns only what the provider actually
     * said; the caller decides whether it is pending, completed or failed.
     *
     * @return array{status: string, gateway_reference: ?string, message: ?string}
     */
    public function submit(Refund $refund, PaymentTransaction $payment): array
    {
        $gateway = $this->gatewayForPayment($payment);
        if (! $gateway) {
            throw new DomainException('No enabled gateway account with credentials is available for this payment.');
        }
        if (! $this->supportsAutomatedRefunds($gateway)) {
            throw new DomainException('Automated refunds are not implemented for this provider. Choose customer credit or record a manual refund.');
        }

        return match ($gateway->provider) {
            'paystack' => $this->submitPaystack($gateway, $refund, $payment),
            default => throw new DomainException('Automated refunds are not implemented for this provider.'),
        };
    }

    /** @return array{status: string, gateway_reference: ?string, message: ?string} */
    public function verify(Refund $refund, PaymentTransaction $payment): array
    {
        $gateway = $this->gatewayForPayment($payment);
        if (! $gateway || ! $this->supportsAutomatedRefunds($gateway)) {
            throw new DomainException('Automated refund verification is not available for this payment.');
        }
        if (! filled($refund->gateway_reference)) {
            throw new DomainException('The refund has no provider reference to verify.');
        }

        $response = Http::withToken($gateway->secret_key)->acceptJson()->timeout(20)
            ->get('https://api.paystack.co/refund/'.rawurlencode((string) $refund->gateway_reference));
        $body = $response->json();
        if (! $response->successful() || ! data_get($body, 'status')) {
            throw new DomainException(data_get($body, 'message') ?: 'The provider could not confirm the refund status.');
        }
        $data = data_get($body, 'data', []);

        return [
            'status' => strtolower((string) data_get($data, 'status', 'pending')),
            'gateway_reference' => (string) (data_get($data, 'id') ?: $refund->gateway_reference),
            'message' => is_string(data_get($body, 'message')) ? data_get($body, 'message') : null,
        ];
    }

    /**
     * Parse an authenticated Paystack refund callback.
     *
     * Returns null when the payload is not a refund event, so the existing
     * charge webhook keeps handling payment events unchanged.
     *
     * @return array{valid: bool, refund: ?Refund, status: string, gateway_reference: ?string, message: ?string, amount_minor: ?int, currency: ?string}|null
     */
    public function parsePaystackRefundWebhook(string $payload, string $legacySignature, string $signatureHeader): ?array
    {
        $body = json_decode($payload, true);
        if (! is_array($body)) return null;
        $event = strtolower((string) ($body['event'] ?? ''));
        if (! in_array($event, ['refund.processed', 'refund.failed', 'refund.pending'], true)) return null;

        $gateway = PaymentGateway::query()->where('provider', 'paystack')->whereNotNull('secret_key')
            ->orderByDesc('is_default')->first();
        if (! $gateway) return ['valid' => false, 'refund' => null, 'status' => $event, 'gateway_reference' => null, 'message' => null, 'amount_minor' => null, 'currency' => null];
        if (! $this->payments->validPaystackWebhookSignature($gateway, $payload, $legacySignature, $signatureHeader)) {
            return ['valid' => false, 'refund' => null, 'status' => $event, 'gateway_reference' => null, 'message' => null, 'amount_minor' => null, 'currency' => null];
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $providerReference = data_get($data, 'id') ?? data_get($data, 'refund_reference');
        $refund = is_scalar($providerReference)
            ? Refund::query()->where('gateway_reference', (string) $providerReference)->first()
            : null;
        if (! $refund) {
            // Fall back to the merchant note carrying our own refund reference.
            $note = (string) (data_get($data, 'merchant_note') ?? '');
            if (preg_match('/REF-\d{4}-\d{8}/', $note, $matches)) {
                $refund = Refund::query()->where('refund_reference', $matches[0])->first();
            }
        }

        $amount = data_get($data, 'amount');
        $status = $event === 'refund.processed' ? 'processed' : ($event === 'refund.failed' ? 'failed' : 'pending');

        return [
            'valid' => true,
            'refund' => $refund,
            'status' => $status,
            'gateway_reference' => is_scalar($providerReference) ? (string) $providerReference : null,
            'message' => is_string(data_get($data, 'status')) ? data_get($data, 'status') : null,
            'amount_minor' => is_numeric($amount) ? (int) $amount : null,
            'currency' => is_string(data_get($data, 'currency')) ? strtoupper((string) data_get($data, 'currency')) : null,
        ];
    }

    /** @return array{status: string, gateway_reference: ?string, message: ?string} */
    private function submitPaystack(PaymentGateway $gateway, Refund $refund, PaymentTransaction $payment): array
    {
        $transaction = $payment->provider_reference ?: $payment->transaction_reference;
        $response = Http::withToken($gateway->secret_key)->acceptJson()->timeout(20)
            ->post('https://api.paystack.co/refund', [
                'transaction' => $transaction,
                'amount' => PaymentAllocationService::toMinorUnits((string) $refund->amount),
                'currency' => $refund->currency,
                'customer_note' => mb_substr($refund->reason, 0, 255),
                'merchant_note' => mb_substr($refund->refund_reference.' · '.$refund->reason, 0, 255),
            ]);
        $body = $response->json();
        if (! $response->successful() || ! data_get($body, 'status')) {
            return [
                'status' => 'failed',
                'gateway_reference' => null,
                'message' => is_string(data_get($body, 'message')) ? data_get($body, 'message') : 'The provider rejected the refund request.',
            ];
        }
        $data = data_get($body, 'data', []);

        return [
            'status' => strtolower((string) data_get($data, 'status', 'pending')),
            'gateway_reference' => data_get($data, 'id') !== null ? (string) data_get($data, 'id') : (data_get($data, 'refund_reference') !== null ? (string) data_get($data, 'refund_reference') : null),
            'message' => is_string(data_get($body, 'message')) ? data_get($body, 'message') : null,
        ];
    }

    private function hasCredentials(PaymentGateway $gateway): bool
    {
        return match ($gateway->provider) {
            'paystack' => filled($gateway->public_key) && filled($gateway->secret_key),
            'hubtel' => filled($gateway->public_key) && filled($gateway->secret_key)
                && filled(data_get($gateway->configuration, 'collection_account_number')),
            'korapay' => filled($gateway->secret_key),
            default => false,
        };
    }
}
