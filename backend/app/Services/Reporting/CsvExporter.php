<?php

namespace App\Services\Reporting;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV export using the same rows the visible report produced.
 * Every cell is escaped against spreadsheet formula injection.
 */
final class CsvExporter
{
    /** @param iterable<array<int, mixed>> $rows */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map([self::class, 'safe'], $headers));
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'safe'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function safe(mixed $value): string
    {
        $value = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
