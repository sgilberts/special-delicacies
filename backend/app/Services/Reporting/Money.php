<?php

namespace App\Services\Reporting;

/**
 * Integer minor-unit money helpers for reporting.
 *
 * Reporting reads SQL aggregates (which return decimal strings) rather than
 * Eloquent models, so it needs a signed parser. Stage 4A–4C arithmetic keeps
 * using PaymentAllocationService::toMinorUnits() for positive money values;
 * this class only converts what a report has already aggregated.
 */
final class Money
{
    public static function minor(mixed $amount): int
    {
        $value = trim((string) ($amount ?? '0'));
        if ($value === '' || $value === '0') {
            return 0;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        if (! preg_match('/^(\d{1,15})(?:\.(\d+))?$/', $value, $parts)) {
            return 0;
        }

        $minor = ((int) $parts[1] * 100) + (int) substr(str_pad($parts[2] ?? '', 2, '0'), 0, 2);

        return $negative ? -$minor : $minor;
    }

    public static function decimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param array<int, int> $values */
    public static function sum(array $values): int
    {
        return array_sum($values);
    }
}
