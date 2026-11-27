<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GatewayPaymentService
{
    public function __construct(private PaymentTransactionService $transactions) {}

    public function configuredGateway(): ?PaymentGateway
    {
        $gateway = PaymentGateway::where('is_enabled', true)->where('is_default', true)
            ->whereNotNull('secret_key')->first();
        return $gateway && $this->hasCredentials($gateway) ? $gateway : null;
    }

    public function initialize(Order $order): string
    {
        if ($order->payment_reference && $order->gateway_checkout_url) return $order->gateway_checkout_url;

        $gateway = $order->payment_gateway_id
            ? PaymentGateway::find($order->payment_gateway_id)
            : $this->configuredGateway();
        if (! $gateway || ! $gateway->is_enabled || ! $gateway->secret_key
            || (! $order->payment_gateway_id && ! $gateway->is_default) || ! $this->hasCredentials($gateway)) {
            throw new RuntimeException('Online payment is not configured. Please choose cash on delivery or contact the restaurant.');
        }
        if (! $order->customer_email) throw new RuntimeException('An email address is required for online payment.');

        $reference = 'SD'.strtoupper(bin2hex(random_bytes(10)));
        if (! $order->payment_gateway_id) {
            $order->forceFill(['payment_gateway_id' => $gateway->id])->save();
        }
        $order->forceFill(['payment_reference' => $reference, 'gateway_checkout_url' => null])->save();
        $this->transactions->recordPending($order->fresh(['paymentGateway']));
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $callbackUrl = $frontendUrl.'/payment/result?reference='.rawurlencode($reference);
        $webhookUrl = rtrim(config('app.url'), '/').'/api/v1/payments/'.$gateway->provider.'/webhook';
        try {
            $checkoutUrl = match ($gateway->provider) {
                'paystack' => $this->initializePaystack($gateway, $order, $reference, $callbackUrl),
                'hubtel' => $this->initializeHubtel($gateway, $order, $reference, $callbackUrl, $webhookUrl),
                'korapay' => $this->initializeKora($gateway, $order, $reference, $callbackUrl, $webhookUrl),
                default => throw new RuntimeException('This online payment provider is not supported.'),
            };
        } catch (\Throwable $exception) {
            $this->transactions->recordGatewayStatus($order, 'failed', $reference);
            throw $exception;
        }

        $order->forceFill([
            'payment_gateway_id' => $gateway->id,
            'payment_reference' => $reference,
            'gateway_checkout_url' => $checkoutUrl,
        ])->save();

        return $checkoutUrl;
    }

    public function verify(string $reference): ?Order
    {
        $order = Order::where('payment_reference', $reference)->first();
        if (! $order) return null;
        if ($order->payment_status === Order::PAYMENT_STATUS_PAID) return $order;

        $gateway = $this->gatewayFor($order);
        if (! $gateway) return $order;

        $providerStatus = match ($gateway->provider) {
            'paystack' => $this->verifyPaystack($gateway, $order, $reference),
            'hubtel' => $this->verifyHubtel($gateway, $order, $reference),
            'korapay' => $this->verifyKora($gateway, $order, $reference),
            default => null,
        };

        return $order->fresh()->setAttribute('provider_payment_status', $providerStatus);
    }

    public function consumeWebhook(string $provider, string $payload, string $signature, string $paystackSignature = ''): bool
    {
        $body = json_decode($payload, true);
        if (! is_array($body)) return false;

        if ($provider === 'paystack') {
            $data = $body['data'] ?? [];
            $reference = $data['reference'] ?? null;
            $order = is_string($reference) ? Order::where('payment_reference', $reference)->first() : null;
            $gateway = $order ? $this->gatewayFor($order) : null;
            // Legacy references can predate payment_gateway_id. Fall back to the
            // configured Paystack account only for those historical orders.
            if (! $gateway && $order && ! $order->payment_gateway_id) {
                $gateway = PaymentGateway::where('provider', 'paystack')->whereNotNull('secret_key')
                    ->orderByDesc('is_default')->first();
            }
            if (! $gateway || $gateway->provider !== 'paystack'
                || ! $this->validPaystackWebhookSignature($gateway, $payload, $signature, $paystackSignature)) return false;

            if (($body['event'] ?? null) === 'charge.failed' && $order) {
                $this->transactions->recordGatewayStatus($order, 'failed', isset($data['id']) ? (string) $data['id'] : null);
                return true;
            }
            if (($body['event'] ?? null) !== 'charge.success') return true;
            if ($order
                && ($data['status'] ?? null) === 'success'
                && ($data['currency'] ?? null) === 'GHS'
                && (int) ($data['amount'] ?? 0) === $this->minorAmount($order)) {
                $this->markPaid($order, isset($data['id']) ? (string) $data['id'] : null);
            }
            return true;
        }

        if ($provider === 'korapay') {
            $data = $body['data'] ?? null;
            if (! is_array($data)) return false;
            $reference = $data['reference'] ?? $data['payment_reference'] ?? null;
            $order = is_string($reference) ? Order::where('payment_reference', $reference)->first() : null;
            $gateway = $order ? $this->gatewayFor($order) : null;
            if (! $gateway || $gateway->provider !== 'korapay') return true;

            // Kora signs the JSON encoding of the data object. Match PHP's
            // documented json_encode representation exactly after parsing.
            $canonicalData = json_encode($data);
            if (! is_string($canonicalData)) return false;
            $expected = hash_hmac('sha256', $canonicalData, $gateway->secret_key);
            if (! hash_equals($expected, $signature)) return false;

            $event = is_array($body['event'] ?? null) ? ($body['event']['type'] ?? null) : ($body['event'] ?? null);
            if (in_array($event, ['charge.failed', 'charge.cancelled'], true) && $order) {
                $this->transactions->recordGatewayStatus($order, $event === 'charge.cancelled' ? 'cancelled' : 'failed', isset($data['reference']) ? (string) $data['reference'] : null);
                return true;
            }
            if ($event === 'charge.success' && ($data['status'] ?? null) === 'success'
                && ($data['currency'] ?? null) === 'GHS' && $this->majorAmountMatches($data['amount'] ?? null, $order)) {
                $this->markPaid($order, isset($data['transaction_id']) ? (string) $data['transaction_id'] : (isset($data['reference']) ? (string) $data['reference'] : null));
            }
            return true;
        }

        if ($provider === 'hubtel') {
            $data = $body['data'] ?? $body;
            $reference = data_get($data, 'clientReference') ?? data_get($data, 'ClientReference');
            if (! is_string($reference) || $reference === '') return true;
            $order = Order::where('payment_reference', $reference)->first();
            if (! $order || $this->gatewayFor($order)?->provider !== 'hubtel') return true;
            // Hubtel's online checkout callback is reconciled against its authenticated
            // transaction-status API before the order can be marked paid.
            $this->verifyHubtel($this->gatewayFor($order), $order, $reference);
            return true;
        }

        return false;
    }

    private function initializePaystack(PaymentGateway $gateway, Order $order, string $reference, string $callbackUrl): string
    {
        $response = Http::withToken($gateway->secret_key)->acceptJson()->timeout(20)
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $order->customer_email,
                'amount' => $this->minorAmount($order),
                'currency' => 'GHS',
                'reference' => $reference,
                'callback_url' => $callbackUrl,
                'channels' => ['card', 'mobile_money', 'bank', 'bank_transfer', 'ussd'],
                'metadata' => ['order_number' => $order->order_number],
            ]);
        $body = $response->json();
        $url = data_get($body, 'data.authorization_url');
        if (! $response->successful() || ! data_get($body, 'status') || ! is_string($url)) {
            throw new RuntimeException(data_get($body, 'message') ?: 'Paystack could not start checkout. Please try again.');
        }
        return $url;
    }

    private function initializeHubtel(PaymentGateway $gateway, Order $order, string $reference, string $callbackUrl, string $webhookUrl): string
    {
        $response = Http::withBasicAuth($gateway->public_key, $gateway->secret_key)->acceptJson()->timeout(20)
            ->post('https://payproxyapi.hubtel.com/items/initiate', [
                'totalAmount' => (float) $order->total,
                'description' => 'Order '.preg_replace('/[^A-Za-z0-9 -]/', '', $order->order_number),
                'callbackUrl' => $webhookUrl,
                'returnUrl' => $callbackUrl,
                'cancellationUrl' => $callbackUrl,
                'merchantAccountNumber' => data_get($gateway->configuration, 'collection_account_number'),
                'clientReference' => $reference,
                'payeeName' => $order->customer_name,
                'payeeMobileNumber' => $order->customer_phone,
                'payeeEmail' => $order->customer_email,
            ]);
        $body = $response->json();
        $url = data_get($body, 'data.checkoutUrl');
        if (! $response->successful() || data_get($body, 'responseCode') !== '0000' || ! is_string($url)) {
            throw new RuntimeException(data_get($body, 'message') ?: 'Hubtel could not start checkout. Please try again.');
        }
        return $url;
    }

    private function initializeKora(PaymentGateway $gateway, Order $order, string $reference, string $callbackUrl, string $webhookUrl): string
    {
        $response = Http::withToken($gateway->secret_key)->acceptJson()->timeout(20)
            ->post('https://api.korapay.com/merchant/api/v1/charges/initialize', [
                'amount' => (float) $order->total,
                'currency' => 'GHS',
                'reference' => $reference,
                'redirect_url' => $callbackUrl,
                'notification_url' => $webhookUrl,
                'narration' => 'Restaurant order '.$order->order_number,
                'channels' => ['mobile_money', 'card', 'bank_transfer'],
                'customer' => ['name' => $order->customer_name, 'email' => $order->customer_email],
            ]);
        $body = $response->json();
        $url = data_get($body, 'data.checkout_url');
        if (! $response->successful() || ! data_get($body, 'status') || ! is_string($url)) {
            throw new RuntimeException(data_get($body, 'message') ?: 'Kora could not start checkout. Please try again.');
        }
        return $url;
    }

    private function verifyPaystack(PaymentGateway $gateway, Order $order, string $reference): ?string
    {
        $response = Http::withToken($gateway->secret_key)->acceptJson()->timeout(20)
            ->get('https://api.paystack.co/transaction/verify/'.rawurlencode($reference));
        $body = $response->json();
        $data = data_get($body, 'data', []);
        if ($response->successful() && data_get($body, 'status') && data_get($data, 'status') === 'success'
            && data_get($data, 'reference') === $reference && data_get($data, 'currency') === 'GHS'
            && (int) data_get($data, 'amount') === $this->minorAmount($order)) $this->markPaid($order, isset($data['id']) ? (string) $data['id'] : null);
        elseif ($response->successful() && in_array(strtolower((string) data_get($data, 'status')), ['failed', 'cancelled'], true)) {
            $this->transactions->recordGatewayStatus($order, strtolower((string) data_get($data, 'status')), isset($data['id']) ? (string) $data['id'] : null);
        }
        return is_string(data_get($data, 'status')) ? data_get($data, 'status') : null;
    }

    private function verifyHubtel(PaymentGateway $gateway, Order $order, string $reference): ?string
    {
        $account = (string) data_get($gateway->configuration, 'collection_account_number', '');
        if ($account === '') return null;
        $response = Http::withBasicAuth($gateway->public_key, $gateway->secret_key)->acceptJson()->timeout(20)
            ->get('https://api-txnstatus.hubtel.com/transactions/'.rawurlencode($account).'/status', ['clientReference' => $reference]);
        $body = $response->json();
        $data = data_get($body, 'data', []);
        if ($response->successful() && (data_get($body, 'responseCode') === '0000' || data_get($body, 'message') === 'Successful')
            && strtolower((string) data_get($data, 'status')) === 'paid'
            && data_get($data, 'clientReference') === $reference
            && $this->currencyMatches(data_get($data, 'currencyCode'))
            && $this->majorAmountMatches(data_get($data, 'amount'), $order)) $this->markPaid($order, isset($data['transactionId']) ? (string) $data['transactionId'] : null);
        elseif ($response->successful() && in_array(strtolower((string) data_get($data, 'status')), ['failed', 'cancelled'], true)) {
            $this->transactions->recordGatewayStatus($order, strtolower((string) data_get($data, 'status')), $reference);
        }
        $status = data_get($data, 'status');
        return is_string($status) ? strtolower($status) : null;
    }

    private function verifyKora(PaymentGateway $gateway, Order $order, string $reference): ?string
    {
        $response = Http::withToken($gateway->secret_key)->acceptJson()->timeout(20)
            ->get('https://api.korapay.com/merchant/api/v1/charges/'.rawurlencode($reference));
        $body = $response->json();
        $data = data_get($body, 'data', []);
        if ($response->successful() && data_get($body, 'status') && data_get($data, 'status') === 'success'
            && data_get($data, 'reference') === $reference && data_get($data, 'currency') === 'GHS'
            && $this->majorAmountMatches(data_get($data, 'amount'), $order)) $this->markPaid($order, isset($data['reference']) ? (string) $data['reference'] : null);
        elseif ($response->successful() && in_array(strtolower((string) data_get($data, 'status')), ['failed', 'cancelled'], true)) {
            $this->transactions->recordGatewayStatus($order, strtolower((string) data_get($data, 'status')), $reference);
        }
        return is_string(data_get($data, 'status')) ? data_get($data, 'status') : null;
    }

    private function gatewayFor(Order $order): ?PaymentGateway
    {
        if ($order->payment_gateway_id) return PaymentGateway::find($order->payment_gateway_id);
        // Orders created before gateway IDs were stored used Paystack references.
        return PaymentGateway::where('provider', 'paystack')->whereNotNull('secret_key')->orderByDesc('is_default')->first();
    }

    /** Shared with RefundGatewayService so refund callbacks use the same authenticated contract. */
    public function validPaystackWebhookSignature(PaymentGateway $gateway, string $payload, string $legacySignature, string $signatureHeader): bool
    {
        if ($signatureHeader !== '') {
            $timestamp = null;
            $signatures = [];
            foreach (explode(',', $signatureHeader) as $part) {
                $pair = explode('=', trim($part), 2);
                if (count($pair) !== 2) continue;
                if ($pair[0] === 't' && ctype_digit($pair[1])) $timestamp = (int) $pair[1];
                if ($pair[0] === 'v1') {
                    $candidate = explode(':', $pair[1], 2);
                    if (count($candidate) === 2) $signatures[] = $candidate[1];
                }
            }

            if ($timestamp === null || abs(time() - $timestamp) > 300 || ! filled($gateway->webhook_secret)) return false;
            $expected = hash_hmac('sha512', $timestamp.'.'.$payload, $gateway->webhook_secret);
            foreach ($signatures as $candidate) {
                if (preg_match('/^[a-f0-9]{128}$/i', $candidate) && hash_equals($expected, strtolower($candidate))) return true;
            }
            return false;
        }

        return preg_match('/^[a-f0-9]{128}$/i', $legacySignature)
            && hash_equals(hash_hmac('sha512', $payload, $gateway->secret_key), strtolower($legacySignature));
    }

    private function minorAmount(Order $order): int
    {
        return (int) round((float) $order->total * 100);
    }

    private function majorAmountMatches(mixed $amount, Order $order): bool
    {
        return is_numeric($amount) && (int) round((float) $amount * 100) === $this->minorAmount($order);
    }

    private function currencyMatches(mixed $currency): bool
    {
        // Hubtel may return currencyCode=null for GHS collection status checks.
        return $currency === null || $currency === '' || strtoupper((string) $currency) === 'GHS';
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

    private function markPaid(Order $order, ?string $providerReference = null): void
    {
        $this->transactions->recordGatewaySuccess($order, $providerReference);
    }
}
