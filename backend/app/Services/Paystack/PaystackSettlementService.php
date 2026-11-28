<?php

namespace App\Services\Paystack;

use App\Exceptions\PaystackSettlementException;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\Cache;

/**
 * Orchestrates read-only Paystack settlement retrieval for the reconciliation
 * workbench: connection state, settlement listing/detail, transaction
 * retrieval and the read-only reconciliation preview.
 *
 * Diagnostic state (last success/failure) is kept in the application cache. It
 * is operational metadata only — no settlement, statement or fee data is
 * persisted anywhere (decision D13 pending).
 */
final class PaystackSettlementService
{
    private const STATUS_CACHE_KEY = 'paystack.settlement.status';

    public const STATE_NOT_CONFIGURED = 'not_configured';

    public const STATE_UNVERIFIED = 'unverified';

    public const STATE_CONNECTED = 'connected';

    public const STATE_AUTHENTICATION_FAILED = 'authentication_failed';

    public const STATE_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const STATE_RATE_LIMITED = 'rate_limited';

    public const STATE_INVALID_RESPONSE = 'invalid_response';

    public const STATE_REQUEST_REJECTED = 'request_rejected';

    private const STATE_LABELS = [
        self::STATE_NOT_CONFIGURED => 'Not configured',
        self::STATE_UNVERIFIED => 'Configured — not yet verified',
        self::STATE_CONNECTED => 'Connected',
        self::STATE_AUTHENTICATION_FAILED => 'Authentication failed',
        self::STATE_PROVIDER_UNAVAILABLE => 'Provider unavailable',
        self::STATE_RATE_LIMITED => 'Rate limited by the provider',
        self::STATE_INVALID_RESPONSE => 'Unreadable provider response',
        self::STATE_REQUEST_REJECTED => 'Provider rejected the request',
    ];

    public function __construct(
        private readonly PaystackSettlementClient $client,
        private readonly PaystackSettlementMapper $mapper,
        private readonly PaystackSettlementReconciliationPreviewService $preview,
        private readonly AuditService $audit,
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $configured = $this->client->isConfigured();
        $recorded = Cache::get(self::STATUS_CACHE_KEY, []);

        $state = $configured
            ? (string) ($recorded['state'] ?? self::STATE_UNVERIFIED)
            : self::STATE_NOT_CONFIGURED;

        return [
            'provider' => 'paystack',
            'configured' => $configured,
            'settlement_enabled' => (bool) config('services.paystack.settlement_enabled', false),
            'state' => $state,
            'state_label' => self::STATE_LABELS[$state] ?? $state,
            'currency' => PaystackSettlementMapper::SUPPORTED_CURRENCY,
            'read_only' => true,
            'last_success_at' => $recorded['last_success_at'] ?? null,
            'last_failure_at' => $recorded['last_failure_at'] ?? null,
            'last_error' => $recorded['last_error'] ?? null,
            'restrictions' => [
                'Provider data is retrieved and compared only; nothing is posted to the ledger.',
                'No settlement is marked financially reconciled by this integration.',
                'Owner decisions D5, D12 and D13 remain pending.',
            ],
        ];
    }

    /**
     * Authenticated, read-only connection check. Audited because it exercises
     * the merchant credentials.
     *
     * @return array<string, mixed>
     */
    public function testConnection(User $actor): array
    {
        try {
            $this->client->connectionProbe();
        } catch (PaystackSettlementException $exception) {
            $this->recordFailure($exception);
            $this->audit->log(
                $actor,
                'financials.paystack.connection_tested',
                'Paystack settlement connection test failed with state '.$exception->state().'.',
                null,
                null,
                ['provider' => 'paystack', 'state' => $exception->state(), 'http_status' => $exception->httpStatus()],
            );

            return $this->status();
        }

        $this->recordSuccess();
        $this->audit->log(
            $actor,
            'financials.paystack.connection_tested',
            'Paystack settlement connection test succeeded.',
            null,
            null,
            ['provider' => 'paystack', 'state' => self::STATE_CONNECTED],
        );

        return $this->status();
    }

    /**
     * @param  array{per_page?:int,page?:int,status?:?string,from?:?string,to?:?string,search?:?string}  $filters
     * @return array<string, mixed>
     */
    public function settlements(array $filters): array
    {
        $result = $this->call(fn (): array => $this->client->listSettlements($filters));

        $settlements = array_map(fn (array $row): array => $this->mapper->settlement($row), $result['data']);

        // Paystack has no settlement search parameter: the filter applies to the
        // page that was just retrieved and is labelled as such in the response.
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = strtolower($search);
            $settlements = array_values(array_filter(
                $settlements,
                fn (array $settlement): bool => str_contains(strtolower((string) $settlement['id']), $needle)
                    || str_contains(strtolower((string) $settlement['settlement_date']), $needle)
                    || str_contains(strtolower((string) $settlement['status']), $needle),
            ));
        }

        return [
            'state' => self::STATE_CONNECTED,
            'data' => $settlements,
            'meta' => [
                'page' => $result['meta']['page'] ?? 1,
                'page_count' => $result['meta']['page_count'] ?? 1,
                'per_page' => $result['meta']['per_page'] ?? null,
                'total' => $result['meta']['total'] ?? 0,
                'filtered' => $search !== '',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function settlement(string $settlementId): array
    {
        $raw = $this->call(fn (): array => $this->client->settlement($settlementId));
        $settlement = $this->mapper->settlement($raw);

        if (($settlement['id'] ?? '') === '') {
            throw PaystackSettlementException::invalidResponse('The settlement response is missing its identifier.');
        }

        return $settlement;
    }

    /** @return array<string, mixed> */
    public function transactions(string $settlementId): array
    {
        $result = $this->call(fn (): array => $this->client->settlementTransactions($settlementId));

        return [
            'state' => self::STATE_CONNECTED,
            'source' => $result['source'],
            'data' => array_map(fn (array $row): array => $this->mapper->transaction($row), $result['rows']),
            'meta' => ['count' => count($result['rows'])],
        ];
    }

    /**
     * Generate the read-only reconciliation preview for one settlement.
     * The only write is an administrative audit entry describing the preview.
     *
     * @return array<string, mixed>
     */
    public function preview(string $settlementId, User $actor): array
    {
        $settlement = $this->settlement($settlementId);
        $result = $this->call(fn (): array => $this->client->settlementTransactions($settlementId));

        $transactions = array_map(fn (array $row): array => $this->mapper->transaction($row), $result['rows']);
        $preview = $this->preview->build($settlement, $transactions, $result['source']);

        $this->audit->log(
            $actor,
            'financials.paystack.preview_generated',
            "Generated a read-only Paystack reconciliation preview for settlement {$settlementId}.",
            null,
            null,
            [
                'provider' => 'paystack',
                'settlement_id' => $settlementId,
                'provider_transactions' => $preview['summary']['provider_transactions'],
                'classifications' => $preview['summary']['classifications'],
            ],
        );

        return $preview;
    }

    /**
     * Run a provider call, recording the diagnostic outcome.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(callable $call): mixed
    {
        try {
            $result = $call();
        } catch (PaystackSettlementException $exception) {
            $this->recordFailure($exception);

            throw $exception;
        }

        $this->recordSuccess();

        return $result;
    }

    private function recordSuccess(): void
    {
        $previous = Cache::get(self::STATUS_CACHE_KEY, []);

        Cache::put(self::STATUS_CACHE_KEY, [
            'state' => self::STATE_CONNECTED,
            'last_success_at' => now()->toIso8601String(),
            'last_failure_at' => $previous['last_failure_at'] ?? null,
            'last_error' => null,
        ], now()->addDay());
    }

    private function recordFailure(PaystackSettlementException $exception): void
    {
        $previous = Cache::get(self::STATUS_CACHE_KEY, []);

        Cache::put(self::STATUS_CACHE_KEY, [
            'state' => $exception->state(),
            'last_success_at' => $previous['last_success_at'] ?? null,
            'last_failure_at' => now()->toIso8601String(),
            'last_error' => [
                'state' => $exception->state(),
                'message' => $exception->getMessage(),
                'http_status' => $exception->httpStatus(),
            ],
        ], now()->addDay());
    }
}
