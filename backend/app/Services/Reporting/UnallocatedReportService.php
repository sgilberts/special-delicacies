<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\LazyCollection;

/** Report C — Unallocated Receipts. Failed, cancelled and unverified money is never "available". */
final class UnallocatedReportService
{
    public const BUCKETS = ['0-7', '8-30', '31-60', '61-90', '90+'];

    public function __construct(private readonly ReceiptLedger $ledger) {}

    public function register(array $filters, ReportPeriod $period, bool $withContacts = false): LengthAwarePaginator
    {
        $query = $this->filtered($filters, $period);

        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $column = ($filters['sort'] ?? '') === 'amount' ? ReceiptLedger::AVAILABLE_SQL : ReceiptLedger::RECEIPT_DATE_SQL;

        return $query->orderByRaw($column.' '.$direction)->orderBy('pt.id')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(function (object $row) use ($withContacts) {
                $data = $this->ledger->row($row, $withContacts);
                $data['age_bucket'] = ReceiptLedger::ageBucket($data['age_days']);

                return $data;
            });
    }

    public function summary(array $filters, ReportPeriod $period): array
    {
        $asOf = $period->end->startOfDay();
        $cutoffs = [
            '0-7' => [$asOf->subDays(7)->format('Y-m-d 00:00:00'), null],
            '8-30' => [$asOf->subDays(30)->format('Y-m-d 00:00:00'), $asOf->subDays(7)->format('Y-m-d 00:00:00')],
            '31-60' => [$asOf->subDays(60)->format('Y-m-d 00:00:00'), $asOf->subDays(30)->format('Y-m-d 00:00:00')],
            '61-90' => [$asOf->subDays(90)->format('Y-m-d 00:00:00'), $asOf->subDays(60)->format('Y-m-d 00:00:00')],
            '90+' => [null, $asOf->subDays(90)->format('Y-m-d 00:00:00')],
        ];

        $buckets = [];
        $total = 0;
        $count = 0;

        foreach ($cutoffs as $label => [$newerThan, $olderThan]) {
            $query = $this->filtered(['currency' => $filters['currency'] ?? null], $period);
            if ($newerThan !== null) {
                $query->whereRaw(ReceiptLedger::RECEIPT_DATE_SQL.' >= ?', [$newerThan]);
            }
            if ($olderThan !== null) {
                $query->whereRaw(ReceiptLedger::RECEIPT_DATE_SQL.' < ?', [$olderThan]);
            }
            $row = $query->select(\Illuminate\Support\Facades\DB::raw(
                'COUNT(*) as receipts, SUM('.ReceiptLedger::AVAILABLE_SQL.') as amount'
            ))->first();
            $amount = Money::minor($row->amount ?? 0);
            $buckets[$label] = ['count' => (int) ($row->receipts ?? 0), 'amount' => Money::decimal($amount)];
            $total += $amount;
            $count += (int) ($row->receipts ?? 0);
        }

        return [
            'as_of' => $asOf->toDateString(),
            'timezone' => (string) config('app.timezone', 'Africa/Accra'),
            'buckets' => $buckets,
            'receipts' => $count,
            'total_amount' => Money::decimal($total),
        ];
    }

    public function exportRows(array $filters, ReportPeriod $period, bool $withContacts = false): LazyCollection
    {
        return $this->filtered($filters, $period)->orderBy('pt.id')->cursor()
            ->map(function (object $row) use ($withContacts) {
                $data = $this->ledger->row($row, $withContacts);
                $data['age_bucket'] = ReceiptLedger::ageBucket($data['age_days']);

                return $data;
            })->take(50000);
    }

    private function filtered(array $filters, ReportPeriod $period): Builder
    {
        // Only invoice-purpose receipts can be allocated to an invoice. An order
        // receipt is settled against its order, so treating its residual as
        // "unallocated money" would overstate funds held.
        $query = $this->ledger->applyFilters($this->ledger->baseQuery(), $filters, $period)
            ->where('pt.status', 'succeeded')
            ->where('pt.payment_purpose', 'invoice')
            ->whereNotNull('pt.received_at')
            ->whereRaw(ReceiptLedger::AVAILABLE_SQL.' > 0');

        if (filled($filters['bucket'] ?? null) && in_array($filters['bucket'], self::BUCKETS, true)) {
            $asOf = $period->end->startOfDay();
            [$newerThan, $olderThan] = match ($filters['bucket']) {
                '0-7' => [$asOf->subDays(7)->format('Y-m-d 00:00:00'), null],
                '8-30' => [$asOf->subDays(30)->format('Y-m-d 00:00:00'), $asOf->subDays(7)->format('Y-m-d 00:00:00')],
                '31-60' => [$asOf->subDays(60)->format('Y-m-d 00:00:00'), $asOf->subDays(30)->format('Y-m-d 00:00:00')],
                '61-90' => [$asOf->subDays(90)->format('Y-m-d 00:00:00'), $asOf->subDays(60)->format('Y-m-d 00:00:00')],
                default => [null, $asOf->subDays(90)->format('Y-m-d 00:00:00')],
            };
            if ($newerThan !== null) {
                $query->whereRaw(ReceiptLedger::RECEIPT_DATE_SQL.' >= ?', [$newerThan]);
            }
            if ($olderThan !== null) {
                $query->whereRaw(ReceiptLedger::RECEIPT_DATE_SQL.' < ?', [$olderThan]);
            }
        }

        return $query;
    }
}
