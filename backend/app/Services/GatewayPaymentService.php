<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GatewayPaymentService
{
    private const PROVIDERS = ['paystack', 'hubtel', 'korapay'];

    public function __construct(
        private PaymentTransactionService $transactions,
        private DepositReceiptService $deposits,
    ) {}

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
                'paystack' => $this->initializePaystackCharge(
                    $gateway,
                    (string) $order->customer_email,
                    $this->minorAmount($order),
                    $reference,
                    $callbackUrl,
                    ['order_number' => $order->order_number],
                ),
                'hubtel' => $this->initializeHubtelCharge(
                    $gateway,
                    (float) $order->total,
                    'Order '.preg_replace('/[^A-Za-z0-9 -]/', '', $order->order_number),
                    $callbackUrl,
                    $webhookUrl,
                    $reference,
                    (string) $order->customer_name,
                    (string) $order->customer_phone,
                    (string) $order->customer_email,
                ),
                'korapay' => $this->initializeKoraCharge(
                    $gateway,
                    (float) $order->total,
                    $reference,
                    $callbackUrl,
                    $webhookUrl,
                    'Restaurant order '.$order->order_number,
                    (string) $order->customer_name,
                    (string) $order->customer_email,
                ),
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

        $providerStatus = $this->verifyCharge($gateway, $order, $reference);

        return $order->fresh()->setAttribute('provider_payment_status', $providerStatus);
    }

    /** Active gateways a customer can choose for a deposit. */
    public function availableGateways(): array
    {
        return PaymentGateway::query()->where('is_enabled', true)->orderByDesc('is_default')->orderBy('id')->get()
            ->filter(fn (PaymentGateway $gateway): bool => in_array($gateway->provider, self::PROVIDERS, true) && $this->hasCredentials($gateway))
            ->map(fn (PaymentGateway $gateway): array => [
                'id' => $gateway->id, 'provider' => $gateway->provider, 'name' => $gateway->display_name,
            ])->values()->all();
    }

    public function depositGateway(int $id): PaymentGateway
    {
        $gateway = PaymentGateway::find($id);
        if (! $gateway || ! $gateway->is_enabled || ! in_array($gateway->provider, self::PROVIDERS, true) || ! $this->hasCredentials($gateway)) {
            throw new RuntimeException('Choose an available online payment method.');
        }
        return $gateway;
    }

    public function gatewayForDeposit(PaymentTransaction $transaction): ?PaymentGateway
    {
        $id = (int) data_get($transaction->metadata, 'gateway_id', 0);
        $gateway = $id > 0 ? PaymentGateway::find($id) : null;
        if ($gateway) return $gateway;
        return PaymentGateway::where('provider', $transaction->provider)->whereNotNull('secret_key')
            ->orderByDesc('is_default')->first();
    }

    /** Start a hosted checkout for a standalone deposit receipt. */
    public function initializeDepositCharge(PaymentTransaction $transaction, PaymentGateway $gateway, Customer $customer): string
    {
        if (! $customer->email) throw new RuntimeException('An email address is required for online payment.');

        $reference = $transaction->transaction_reference;
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $callbackUrl = $frontendUrl.'/account/deposits?reference='.rawurlencode($reference);
        $webhookUrl = rtrim(config('app.url'), '/').'/api/v1/payments/'.$gateway->provider.'/webhook';
        $label = 'Deposit '.$reference;

        return match ($gateway->provider) {
            'paystack' => $this->initializePaystackCharge(
                $gateway, (string) $customer->email, $this->minorAmountFor($transaction), $reference, $callbackUrl,
                ['deposit_reference' => $reference, 'payer' => $this->payerReference($transaction)],
            ),
            'hubtel' => $this->initializeHubtelCharge(
                $gateway, (float) $transaction->amount, $label, $callbackUrl, $webhookUrl, $reference,
                $this->payerName($transaction), (string) $customer->phone, (string) $customer->email,
            ),
            'korapay' => $this->initializeKoraCharge(
                $gateway, (float) $transaction->amount, $reference, $callbackUrl, $webhookUrl, $label,
                $this->payerName($transaction), (string) $customer->email,
            ),
            default => throw new RuntimeException('This online payment provider is not supported.'),
        };
    }

    /**
     * Server-side verification of a deposit charge. Returns the provider status.
     * A deposit only completes when the provider confirms the exact reference,
     * amount and currency that this application created.
     */
    public function verifyDepositCharge(PaymentTransaction $transaction): ?string
    {
        $gateway = $this->gatewayForDeposit($transaction);
        if (! $gateway) return null;

        $charge = $this->fetchCharge($gateway, $transaction->transaction_reference);
        if ($charge['paid']
            && $charge['reference'] === $transaction->transaction_reference
            && $charge['currency'] === 'GHS'
            && $charge['amount_minor'] === $this->minorAmountFor($transaction)) {
            $this->completeDeposit($transaction, $charge['provider_reference']);
        } elseif (in_array($charge['status'], ['failed', 'cancelled'], true)) {
            $this->deposits->fail($transaction, $charge['status']);
        }

        return $charge['status'];
    }

    public function consumeWebhook(string $provider, string $payload, string $signature, string $paystackSignature = ''): bool
    {
        $body = json_decode($payload, true);
        if (! is_array($body)) return false;

        if ($provider === 'paystack') {
            $data = $body['data'] ?? [];
            $reference = $data['reference'] ?? null;
            $order = is_string($reference) ? Order::where('payment_reference', $reference)->first() : null;
            $deposit = ! $order && is_string($reference) ? $this->depositTransaction($reference) : null;
            $gateway = $order ? $this->gatewayFor($order) : ($deposit ? $this->gatewayForDeposit($deposit) : null);
            // Legacy references can predate payment_gateway_id. Fall back to the
            // configured Paystack account only for those historical orders.
            if (! $gateway && $order && ! $order->payment_gateway_id) {
                $gateway = PaymentGateway::where('provider', 'paystack')->whereNotNull('secret_key')
                    ->orderByDesc('is_default')->first();
            }
            if (! $gateway || $gateway->provider !== 'paystack'
                || ! $this->validPaystackWebhookSignature($gateway, $payload, $signature, $paystackSignature)) return false;

            if ($deposit) {
                if (($body['event'] ?? null) === 'charge.failed') {
                    $this->deposits->fail($deposit, 'failed');
                    return true;
                }
                if (($body['event'] ?? null) !== 'charge.success') return true;
                if (($data['status'] ?? null) === 'success'
                    && ($data['currency'] ?? null) === 'GHS'
                    && (int) ($data['amount'] ?? 0) === $this->minorAmountFor($deposit)) {
                    $this->completeDeposit($deposit, isset($data['id']) ? (string) $data['id'] : null);
                }
                return true;
            }

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
            $deposit = ! $order && is_string($reference) ? $this->depositTransaction($reference) : null;
            $gateway = $order ? $this->gatewayFor($order) : ($deposit ? $this->gatewayForDeposit($deposit) : null);
            if (! $gateway || $gateway->provider !== 'korapay') return true;

            // Kora signs the JSON encoding of the data object. Match PHP's
            // documented json_encode representation exactly after parsing.
            $canonicalData = json_encode($data);
            if (! is_string($canonicalData)) return false;
            $expected = hash_hmac('sha256', $canonicalData, $gateway->secret_key);
            if (! hash_equals($expected, $signature)) return false;

            $event = is_array($body['event'] ?? null) ? ($body['event']['type'] ?? null) : ($body['event'] ?? null);
            if (in_array($event, ['charge.failed', 'charge.cancelled'], true)) {
                if ($deposit) {
                    $this->deposits->fail($deposit, $event === 'charge.cancelled' ? 'cancelled' : 'failed');
                    return true;
                }
                if ($order) {
                    $this->transactions->recordGatewayStatus($order, $event === 'charge.cancelled' ? 'cancelled' : 'failed', isset($data['reference']) ? (string) $data['reference'] : null);
                }
                return true;
            }
            if ($event === 'charge.success' && ($data['status'] ?? null) === 'success'
                && ($data['currency'] ?? null) === 'GHS'
                && $this->majorAmountMatches($data['amount'] ?? null, $deposit ?: $order)) {
                $providerReference = isset($data['transaction_id']) ? (string) $data['transaction_id'] : (isset($data['reference']) ? (string) $data['reference'] : null);
                if ($deposit) {
                    $this->completeDeposit($deposit, $providerReference);
                } else {
                    $this->markPaid($order, $providerReference);
                }
            }
            return true;
        }

        if ($provider === 'hubtel') {
            $data = $body['data'] ?? $body;
            $reference = data_get($data, 'clientReference') ?? data_get($data, 'ClientReference');
            if (! is_string($reference) || $reference === '') return true;
            $order = Order::where('payment_reference', $reference)->first();
            if ($order) {
                if ($this->gatewayFor($order)?->provider !== 'hubtel') return true;
                // Hubtel's online checkout callback is reconciled against its authenticated
                // transaction-status API before the order can be marked paid.
                $this->verifyHubtel($this->gatewayFor($order), $order, $reference);
                return true;
            }
            $deposit = $this->depositTransaction($reference);
            if ($deposit && $this->gatewayForDeposit($deposit)?->provider === 'hubtel') {
                $this->verifyDepositCharge($deposit);
            }
            return true;
        }

        return false;
    }

    private function initializePaystackCharge(PaymentGateway $gateway, string $email, int $amountMinor, string $reference, string $callbackUrl, array $metadata): string
    {
        $response = Http::withToken($gateway->secret_key)->acceptJson()->timeout(20)
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $email,
                'amount' => $amountMinor,
                'currency' => 'GHS',
                'reference' => $reference,
                'callback_url' => $callbackUrl,
                'channels' => ['card', 'mobile_money', 'bank', 'bank_transfer', 'ussd'],
                'metadata' => $metadata,
            ]);
        $body = $response->json();
        $url = data_get($body, 'data.authorization_url');
        if (! $response->successful() || ! data_get($body, 'status') || ! is_string($url)) {
            throw new RuntimeException(data_get($body, 'message') ?: 'Paystack could not start checkout. Please try again.');
        }
        return $url;
    }

    private function initializeHubtelCharge(PaymentGateway $gateway, float $amount, string $description, string $callbackUrl, string $webhookUrl, string $reference, string $payeeName, string $payeeMobile, string $payeeEmail): string
    {
        $response = Http::withBasicAuth($gateway->public_key, $gateway->secret_key)->acceptJson()->timeout(20)
            ->post('https://payproxyapi.hubtel.com/items/initiate', [
                'totalAmount' => $amount,
                'description' => $description,
                'callbackUrl' => $webhookUrl,
                'returnUrl' => $callbackUrl,
                'cancellationUrl' => $callbackUrl,
                'merchantAccountNumber' => data_get($gateway->configuration, 'collection_account_number'),
                'clientReference' => $reference,
                'payeeName' => $payeeName,
                'payeeMobileNumber' => $payeeMobile,
                'payeeEmail' => $payeeEmail,
            ]);
        $body = $response->json();
        $url = data_get($body, 'data.checkoutUrl');
        if (! $response->successful() || data_get($body, 'responseCode') !== '0000' || ! is_string($url)) {
            throw new RuntimeException(data_get($body, 'message') ?: 'Hubtel could not start checkout. Please try again.');
        }
        return $url;
    }

    private function initializeKoraCharge(PaymentGateway $gateway, float $amount, string $reference, string $callbackUrl, string $webhookUrl, string $narration, string $customerName, string $customerEmail): string
    {
        $response = Http::withToken($gateway->secret_key)->acceptJson()->timeout(20)
            ->post('https://api.korapay.com/merchant/api/v1/charges/initialize', [
                'amount' => $amount,
                'currency' => 'GHS',
                'reference' => $reference,
                'redirect_url' => $callbackUrl,
                'notification_url' => $webhookUrl,
                'narration' => $narration,
                'channels' => ['mobile_money', 'card', 'bank_transfer'],
                'customer' => ['name' => $customerName, 'email' => $customerEmail],
            ]);
        $body = $response->json();
        $url = data_get($body, 'data.checkout_url');
        if (! $response->successful() || ! data_get($body, 'status') || ! is_string($url)) {
            throw new RuntimeException(data_get($body, 'message') ?: 'Kora could not start checkout. Please try again.');
        }
        return $url;
    }

    private function verifyCharge(PaymentGateway $gateway, Order $order, string $reference): ?string
    {
        return match ($gateway->provider) {
            'paystack' => $this->verifyPaystack($gateway, $order, $reference),
            'hubtel' => $this->verifyHubtel($gateway, $order, $reference),
            'korapay' => $this->verifyKora($gateway, $order, $reference),
            default => null,
        };
    }

    private function verifyPaystack(PaymentGateway $gateway, Order $order, string $reference): ?string
    {
        $charge = $this->fetchCharge($gateway, $reference);
        if ($charge['paid'] && $charge['reference'] === $reference
            && $charge['currency'] === 'GHS'
            && $charge['amount_minor'] === $this->minorAmount($order)) {
            $this->markPaid($order, $charge['provider_reference']);
        } elseif (in_array($charge['status'], ['failed', 'cancelled'], true)) {
            $this->transactions->recordGatewayStatus($order, $charge['status'], $charge['provider_reference']);
        }
        return $charge['status'];
    }

    private function verifyHubtel(PaymentGateway $gateway, Order $order, string $reference): ?string
    {
        $charge = $this->fetchCharge($gateway, $reference);
        if ($charge['paid'] && $charge['reference'] === $reference
            && $charge['currency'] === 'GHS'
            && $charge['amount_minor'] === $this->minorAmount($order)) {
            $this->markPaid($order, $charge['provider_reference']);
        } elseif (in_array($charge['status'], ['failed', 'cancelled'], true)) {
            $this->transactions->recordGatewayStatus($order, $charge['status'], $reference);
        }
        return $charge['status'];
    }

    private function verifyKora(PaymentGateway $gateway, Order $order, string $reference): ?string
    {
        $charge = $this->fetchCharge($gateway, $reference);
        if ($charge['paid'] && $charge['reference'] === $reference
            && $charge['currency'] === 'GHS'
            && $charge['amount_minor'] === $this->minorAmount($order)) {
            $this->markPaid($order, $charge['provider_reference']);
        } elseif (in_array($charge['status'], ['failed', 'cancelled'], true)) {
            $this->transactions->recordGatewayStatus($order, $charge['status'], $reference);
        }
        return $charge['status'];
    }

    /**
     * Fetch a provider charge and normalise it for both order and deposit
     * verification: provider status, exact reference, currency and the amount
     * in minor units.
     *
     * @return array{status: ?string, paid: bool, reference: ?string, currency: ?string, amount_minor: ?int, provider_reference: ?string}
     */
    private function fetchCharge(PaymentGateway $gateway, string $reference): array
    {
        return match ($gateway->provider) {
            'paystack' => $this->fetchPaystackCharge($gateway, $reference),
            'hubtel' => $this->fetchHubtelCharge($gateway, $reference),
            'korapay' => $this->fetchKoraCharge($gateway, $reference),
            default => $this->emptyCharge(),
        };
    }

    private function fetchPaystackCharge(PaymentGateway $gateway, string $reference): array
    {
        $response = Http::withToken($gateway->secret_key)->acceptJson()->timeout(20)
            ->get('https://api.paystack.co/transaction/verify/'.rawurlencode($reference));
        $body = $response->json();
        $data = data_get($body, 'data', []);
        $status = is_string(data_get($data, 'status')) ? strtolower((string) data_get($data, 'status')) : null;

        return [
            'status' => $status,
            'paid' => $response->successful() && (bool) data_get($body, 'status') && $status === 'success',
            'reference' => is_string(data_get($data, 'reference')) ? data_get($data, 'reference') : null,
            'currency' => is_string(data_get($data, 'currency')) ? strtoupper((string) data_get($data, 'currency')) : null,
            'amount_minor' => is_numeric(data_get($data, 'amount')) ? (int) data_get($data, 'amount') : null,
            'provider_reference' => isset($data['id']) ? (string) $data['id'] : null,
        ];
    }

    private function fetchHubtelCharge(PaymentGateway $gateway, string $reference): array
    {
        $account = (string) data_get($gateway->configuration, 'collection_account_number', '');
        if ($account === '') return $this->emptyCharge();

        $response = Http::withBasicAuth($gateway->public_key, $gateway->secret_key)->acceptJson()->timeout(20)
            ->get('https://api-txnstatus.hubtel.com/transactions/'.rawurlencode($account).'/status', ['clientReference' => $reference]);
        $body = $response->json();
        $data = data_get($body, 'data', []);
        $status = is_string(data_get($data, 'status')) ? strtolower((string) data_get($data, 'status')) : null;
        $currency = data_get($data, 'currencyCode');
        $successful = $response->successful() && (data_get($body, 'responseCode') === '0000' || data_get($body, 'message') === 'Successful');

        return [
            'status' => $status,
            'paid' => $successful && $status === 'paid',
            'reference' => is_string(data_get($data, 'clientReference')) ? data_get($data, 'clientReference') : null,
            // Hubtel may omit currencyCode for GHS collection status checks.
            'currency' => ($currency === null || $currency === '') ? 'GHS' : strtoupper((string) $currency),
            'amount_minor' => is_numeric(data_get($data, 'amount')) ? (int) round((float) data_get($data, 'amount') * 100) : null,
            'provider_reference' => isset($data['transactionId']) ? (string) $data['transactionId'] : null,
        ];
    }

    private function fetchKoraCharge(PaymentGateway $gateway, string $reference): array
    {
        $response = Http::withToken($gateway->secret_key)->acceptJson()->timeout(20)
            ->get('https://api.korapay.com/merchant/api/v1/charges/'.rawurlencode($reference));
        $body = $response->json();
        $data = data_get($body, 'data', []);
        $status = is_string(data_get($data, 'status')) ? strtolower((string) data_get($data, 'status')) : null;

        return [
            'status' => $status,
            'paid' => $response->successful() && (bool) data_get($body, 'status') && $status === 'success',
            'reference' => is_string(data_get($data, 'reference')) ? data_get($data, 'reference') : null,
            'currency' => is_string(data_get($data, 'currency')) ? strtoupper((string) data_get($data, 'currency')) : null,
            'amount_minor' => is_numeric(data_get($data, 'amount')) ? (int) round((float) data_get($data, 'amount') * 100) : null,
            'provider_reference' => isset($data['transaction_id']) ? (string) $data['transaction_id']
                : (is_string(data_get($data, 'reference')) ? data_get($data, 'reference') : null),
        ];
    }

    private function emptyCharge(): array
    {
        return ['status' => null, 'paid' => false, 'reference' => null, 'currency' => null, 'amount_minor' => null, 'provider_reference' => null];
    }

    private function depositTransaction(string $reference): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('transaction_reference', $reference)
            ->whereNull('order_id')
            ->where('payment_purpose', 'invoice')
            ->first();
    }

    private function completeDeposit(PaymentTransaction $transaction, ?string $providerReference): void
    {
        $intent = (string) data_get($transaction->metadata, 'intent', 'deposit');
        $this->deposits->complete($transaction, $intent, null, [
            'name' => $this->payerName($transaction),
            'reference' => $this->payerReference($transaction),
            'reason' => 'Online '.$intent.' confirmed by '.($transaction->provider ?: 'gateway').' verification.',
        ], $providerReference);
    }

    private function payerName(PaymentTransaction $transaction): string
    {
        return (string) ($transaction->customer?->name ?? $transaction->organisation?->name ?? 'Deposit payer');
    }

    private function payerReference(PaymentTransaction $transaction): string
    {
        return $transaction->organisation_id !== null
            ? 'organisation:'.$transaction->organisation_id
            : 'customer:'.$transaction->customer_id;
    }

    private function gatewayFor(Order $order): ?PaymentGateway
    {
        if ($order->payment_gateway_id) return PaymentGateway::find($order->payment_gateway_id);
        // Orders created before gateway IDs were stored used Paystack references.
        return PaymentGateway::where('provider', 'paystack')->whereNotNull('secret_key')->orderByDesc('is_default')->first();
    }

    private function validPaystackWebhookSignature(PaymentGateway $gateway, string $payload, string $legacySignature, string $signatureHeader): bool
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

    private function minorAmountFor(PaymentTransaction $transaction): int
    {
        return (int) round((float) $transaction->amount * 100);
    }

    private function majorAmountMatches(mixed $amount, Order|PaymentTransaction|null $subject): bool
    {
        if (! $subject || ! is_numeric($amount)) return false;
        $expected = $subject instanceof Order ? $this->minorAmount($subject) : $this->minorAmountFor($subject);
        return (int) round((float) $amount * 100) === $expected;
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
