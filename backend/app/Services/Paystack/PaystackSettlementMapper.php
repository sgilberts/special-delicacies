<?php

namespace App\Services\Paystack;

use App\Services\Reporting\Money;

/**
 * Maps Paystack settlement payloads into the stable shape used by the API,
 * the read-only preview and the frontend.
 *
 * Rules that matter for correctness:
 *  - Amounts stay integer minor units (GHS pesewas). No float arithmetic.
 *  - A monetary field is only exposed when the provider actually returned it;
 *    a missing `total_fees` is never replaced by a calculated fee (D5 pending).
 *  - The provider's status string is preserved verbatim. Only `success` and
 *    `failed` are terminal; `processing` and `pending` are not completed.
 */
final class PaystackSettlementMapper
{
    public const SUPPORTED_CURRENCY = 'GHS';

    /** Provider fields that carry a monetary value, mapped to stable keys. */
    private const SETTLEMENT_AMOUNTS = [
        'total_amount' => 'total_amount',
        'effective_amount' => 'effective_amount',
        'total_fees' => 'total_fees',
        'total_processed' => 'total_processed',
        'deductions' => 'deductions',
    ];

    private const TERMINAL_STATUSES = ['success', 'failed'];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function settlement(array $raw): array
    {
        $status = $this->text($raw, ['status']) ?? 'unknown';
        $currency = $this->currency($this->text($raw, ['currency']));
        $transactions = $raw['transactions'] ?? null;

        return [
            'provider' => 'paystack',
            'id' => $this->text($raw, ['id']),
            'status' => $status,
            'status_is_terminal' => in_array($status, self::TERMINAL_STATUSES, true),
            'currency' => $currency,
            'currency_supported' => $currency === self::SUPPORTED_CURRENCY,
            'settlement_date' => $this->text($raw, ['settlement_date']),
            'provider_amounts' => $this->amounts($raw),
            'transactions_count' => is_array($transactions)
                ? count($transactions)
                : (isset($raw['total_transactions']) && is_numeric($raw['total_transactions']) ? (int) $raw['total_transactions'] : null),
            'created_at' => $this->text($raw, ['createdAt', 'created_at']),
            'updated_at' => $this->text($raw, ['updatedAt', 'updated_at']),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function transaction(array $raw): array
    {
        $currency = $this->currency($this->text($raw, ['currency']));

        return [
            'provider' => 'paystack',
            'id' => $this->text($raw, ['id', 'transaction id']),
            'reference' => $this->text($raw, ['reference']),
            'status' => $this->text($raw, ['status']) ?? 'unknown',
            'currency' => $currency,
            'currency_supported' => $currency === self::SUPPORTED_CURRENCY,
            'amount' => $this->amount($raw, ['amount']),
            'fees' => $this->amount($raw, ['fees']),
            'channel' => $this->text($raw, ['channel']),
            'paid_at' => $this->text($raw, ['paid_at', 'paidAt', 'paid at']),
            'settlement' => $this->text($raw, ['settlement']),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, array{minor:int, decimal:string}>
     */
    private function amounts(array $raw): array
    {
        $amounts = [];

        foreach (self::SETTLEMENT_AMOUNTS as $key => $field) {
            $minor = $this->toMinor($raw[$field] ?? null);
            if ($minor === null) {
                continue;
            }

            $amounts[$key] = ['minor' => $minor, 'decimal' => Money::decimal($minor)];
        }

        return $amounts;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $keys
     * @return array{minor:int, decimal:string}|null
     */
    private function amount(array $raw, array $keys): ?array
    {
        foreach ($keys as $key) {
            $minor = $this->toMinor($raw[$key] ?? null);
            if ($minor !== null) {
                return ['minor' => $minor, 'decimal' => Money::decimal($minor)];
            }
        }

        return null;
    }

    /**
     * Provider JSON amounts are integer minor units. A CSV export carries
     * decimal major units instead, which Money converts with integer maths.
     */
    private function toMinor(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            // Only ever from a decoded JSON float; avoid binary-float drift.
            return (int) round($value);
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        return str_contains($text, '.') || str_contains($text, ',')
            ? Money::minor(str_replace(',', '', $text))
            : (int) $text;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $keys
     */
    private function text(array $raw, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null && $raw[$key] !== '') {
                return (string) $raw[$key];
            }
        }

        return null;
    }

    private function currency(?string $currency): ?string
    {
        return $currency === null ? null : strtoupper($currency);
    }
}
