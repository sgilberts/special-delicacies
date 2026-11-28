<?php

namespace App\Services\Paystack;

use App\Exceptions\PaystackSettlementException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only Paystack settlement API client.
 *
 * Only GET requests are issued: Stage 4D-PAYSTACK may retrieve and inspect
 * settlement data but must never move money, refund, transfer or mutate any
 * provider-side record. The secret key is read from config/services.php and is
 * never logged, echoed or returned.
 *
 * Official endpoints used:
 *  - GET /settlement                       list settlements
 *  - GET /settlement/{id}                  one settlement
 *  - GET /settlement/{id}/transactions     settlement transactions (paginated)
 *  - GET /transaction/export?settlement=…  temporary CSV download link
 *  - GET /transaction?settlement=…&page=…  paginated transaction fallback
 */
final class PaystackSettlementClient
{
    /** Guard against a runaway provider pagination loop. */
    private const MAX_PAGES = 200;

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    public function isConfigured(): bool
    {
        return (bool) config('services.paystack.settlement_enabled', false)
            && filled(config('services.paystack.secret_key'));
    }

    public function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw PaystackSettlementException::notConfigured();
        }
    }

    /**
     * @param  array{per_page?:int,page?:int,status?:?string,from?:?string,to?:?string,subaccount?:?string}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int|string|null>}
     */
    public function listSettlements(array $filters = []): array
    {
        $payload = $this->get('/settlement', array_filter([
            'perPage' => $filters['per_page'] ?? 50,
            'page' => $filters['page'] ?? 1,
            'status' => $filters['status'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'subaccount' => $filters['subaccount'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        return [
            'data' => $this->rows($payload['data'] ?? null),
            'meta' => $this->meta($payload['meta'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    public function settlement(string $settlementId): array
    {
        $payload = $this->get('/settlement/'.rawurlencode($settlementId));
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw PaystackSettlementException::invalidResponse('Paystack did not return a settlement record.');
        }

        return $data;
    }

    /**
     * Every transaction belonging to a settlement.
     *
     * The CSV export is tried first because it is one request instead of many.
     * Its download link expires in roughly 60 seconds, so it is used within this
     * same operation and never stored. Any export problem falls back to the
     * documented paginated transaction endpoint.
     *
     * @return array{source: 'export'|'paginated', rows: array<int, array<string, mixed>>}
     */
    public function settlementTransactions(string $settlementId): array
    {
        $exported = $this->transactionsViaExport($settlementId);
        if ($exported !== null) {
            return ['source' => 'export', 'rows' => $exported];
        }

        return ['source' => 'paginated', 'rows' => $this->transactionsViaPagination($settlementId)];
    }

    /** Cheap authenticated call used to prove the configured key works. */
    public function connectionProbe(): void
    {
        $this->get('/settlement', ['perPage' => 1, 'page' => 1]);
    }

    /** @return array<int, array<string, mixed>> */
    private function transactionsViaPagination(string $settlementId): array
    {
        $rows = [];
        $page = 1;
        $pageCount = 1;

        do {
            $payload = $this->get('/transaction', [
                'settlement' => $settlementId,
                'perPage' => 100,
                'page' => $page,
            ]);

            $rows = array_merge($rows, $this->rows($payload['data'] ?? null));
            $meta = $this->meta($payload['meta'] ?? null);
            $pageCount = max(1, (int) ($meta['page_count'] ?? 1));
            $page++;
        } while ($page <= $pageCount && $page <= self::MAX_PAGES);

        return $rows;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function transactionsViaExport(string $settlementId): ?array
    {
        $path = $this->requestExportPath($settlementId);
        if ($path === null) {
            return null;
        }

        // Two attempts: an expired link is replaced by a brand new export once.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $download = Http::accept('text/csv')->timeout($this->timeout())->get($path);
            } catch (ConnectionException $exception) {
                Log::warning('paystack.settlement.export_download_failed', ['attempt' => $attempt]);

                return null;
            }

            if (in_array($download->status(), [403, 404, 410], true)) {
                if ($attempt === 2) {
                    return null;
                }

                $path = $this->requestExportPath($settlementId);
                if ($path === null) {
                    return null;
                }

                continue;
            }

            if (! $download->successful()) {
                return null;
            }

            $rows = $this->parseCsv($download->body());

            return $rows === [] ? null : $rows;
        }

        return null;
    }

    private function requestExportPath(string $settlementId): ?string
    {
        try {
            $payload = $this->get('/transaction/export', ['settlement' => $settlementId]);
        } catch (PaystackSettlementException $exception) {
            // The export is an optimisation, not a requirement: the paginated
            // endpoint can always supply the same rows.
            return null;
        }

        $path = data_get($payload, 'data.path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Parse the exported CSV into rows keyed by lower-case header name.
     * A file without a `reference` column is not usable and triggers the
     * paginated fallback.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseCsv(string $body): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($body)) ?: [];
        if (count($lines) < 2) {
            return [];
        }

        $headerLine = array_shift($lines);
        $headers = array_map(
            fn (mixed $header): string => strtolower(trim((string) $header)),
            str_getcsv((string) $headerLine),
        );

        if (! in_array('reference', $headers, true)) {
            return [];
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }

            $values = str_getcsv((string) $line);
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $values[$index] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $this->assertConfigured();

        $attempts = max(1, (int) config('services.paystack.retries', 2));
        $sleepMs = max(0, (int) config('services.paystack.retry_sleep_ms', 250));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::withToken((string) config('services.paystack.secret_key'))
                    ->acceptJson()
                    ->timeout($this->timeout())
                    ->get($this->baseUrl().$path, $query);
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    $this->pause($sleepMs);

                    continue;
                }

                Log::warning('paystack.settlement.connection_failed', ['path' => $path, 'attempts' => $attempt]);

                throw PaystackSettlementException::providerUnavailable(
                    'Paystack could not be reached. Try again shortly.',
                    null,
                    $exception,
                );
            }

            if (in_array($response->status(), self::RETRYABLE_STATUSES, true) && $attempt < $attempts) {
                $this->pause($this->retryDelayMs($response, $sleepMs));

                continue;
            }

            return $this->decode($path, $response);
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $path, Response $response): array
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            Log::warning('paystack.settlement.authentication_failed', ['path' => $path, 'status' => $status]);

            throw PaystackSettlementException::authenticationFailed($status);
        }

        if ($status === 429) {
            Log::warning('paystack.settlement.rate_limited', ['path' => $path, 'status' => $status]);

            throw PaystackSettlementException::rateLimited();
        }

        if ($status >= 500) {
            Log::warning('paystack.settlement.provider_error', ['path' => $path, 'status' => $status]);

            throw PaystackSettlementException::providerUnavailable('Paystack is temporarily unavailable. Try again shortly.', $status);
        }

        if (! $response->successful()) {
            Log::warning('paystack.settlement.request_rejected', ['path' => $path, 'status' => $status]);

            throw PaystackSettlementException::requestRejected($status);
        }

        $payload = $response->json();

        if (! is_array($payload) || ! array_key_exists('status', $payload)) {
            Log::warning('paystack.settlement.invalid_response', ['path' => $path, 'status' => $status]);

            throw PaystackSettlementException::invalidResponse('Paystack returned a response this integration cannot read.');
        }

        if ($payload['status'] !== true) {
            Log::warning('paystack.settlement.unsuccessful_payload', ['path' => $path, 'status' => $status]);

            throw PaystackSettlementException::invalidResponse('Paystack did not confirm the settlement request.');
        }

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /** @return array{page:int,page_count:int,total:int,per_page:?int} */
    private function meta(mixed $meta): array
    {
        if (! is_array($meta)) {
            return ['page' => 1, 'page_count' => 1, 'total' => 0, 'per_page' => null];
        }

        return [
            'page' => (int) ($meta['page'] ?? 1),
            'page_count' => (int) ($meta['pageCount'] ?? $meta['page_count'] ?? 1),
            'total' => (int) ($meta['total'] ?? 0),
            'per_page' => isset($meta['perPage']) ? (int) $meta['perPage'] : (isset($meta['per_page']) ? (int) $meta['per_page'] : null),
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.paystack.base_url', 'https://api.paystack.co'), '/');
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.paystack.timeout', 15));
    }

    private function retryDelayMs(Response $response, int $defaultMs): int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_numeric($retryAfter)) {
            return min(2000, max(0, (int) $retryAfter * 1000));
        }

        return $defaultMs;
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
