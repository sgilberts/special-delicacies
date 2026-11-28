<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;

/**
 * A timezone-aware reporting period.
 *
 * Day boundaries are resolved in the application business timezone
 * (Africa/Accra), and the upper bound is the end of the selected day, so a
 * transaction at 23:59 on the "to" date is never silently excluded.
 */
final class ReportPeriod
{
    public const MAX_DAYS = 366;

    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $from,
        public readonly string $to,
    ) {}

    public static function fromFilters(array $filters, int $defaultDays = 30): self
    {
        $timezone = (string) config('app.timezone', 'Africa/Accra');

        $to = filled($filters['to'] ?? null)
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['to'], $timezone)
            : CarbonImmutable::now($timezone)->startOfDay();

        $from = filled($filters['from'] ?? null)
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['from'], $timezone)
            : $to->subDays(max(0, $defaultDays - 1));

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS - 1) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return new self($from->startOfDay(), $to->endOfDay(), $from->toDateString(), $to->toDateString());
    }

    /** The immediately preceding period of the same number of days. */
    public function previous(): self
    {
        $days = (int) $this->start->diffInDays($this->end->startOfDay()) + 1;
        $previousEnd = $this->start->subDay();
        $previousStart = $previousEnd->subDays($days - 1)->startOfDay();

        return new self($previousStart, $previousEnd->endOfDay(), $previousStart->toDateString(), $previousEnd->toDateString());
    }

    /** @return array{0:string,1:string} */
    public function bounds(): array
    {
        return [$this->start->format('Y-m-d H:i:s'), $this->end->format('Y-m-d H:i:s')];
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end->startOfDay()) + 1;
    }

    public function meta(string $dateBasis): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'date_basis' => $dateBasis,
            'timezone' => (string) config('app.timezone', 'Africa/Accra'),
            'days' => $this->days(),
            'comparison' => ['from' => $this->previous()->from, 'to' => $this->previous()->to],
        ];
    }
}
