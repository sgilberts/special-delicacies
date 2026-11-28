<?php

namespace App\Services\Reporting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

/** Report A — Payment and Receipt Register. */
final class ReceiptRegisterService
{
    public const SORTABLE = ['received_at', 'amount', 'created_at'];

    public function __construct(private readonly ReceiptLedger $ledger) {}

    public function register(array $filters, ReportPeriod $period, bool $withContacts = false): LengthAwarePaginator
    {
        $query = $this->ledger->applyFilters($this->ledger->baseQuery(), $filters, $period);

        $sort = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'received_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $column = match ($sort) {
            'amount' => 'pt.amount',
            'created_at' => 'pt.created_at',
            default => ReceiptLedger::RECEIPT_DATE_SQL,
        };

        return $query->orderByRaw($column.' '.$direction)->orderBy('pt.id')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (object $row) => $this->ledger->row($row, $withContacts));
    }

    public function summary(array $filters, ReportPeriod $period): array
    {
        $rows = $this->ledger->aggregate(
            $filters,
            $period,
            'pt.status, pt.payment_method, pt.provider, pt.payment_purpose, pt.currency, COUNT(*) as receipts, SUM(pt.amount) as amount',
        )->groupBy('pt.status', 'pt.payment_method', 'pt.provider', 'pt.payment_purpose', 'pt.currency')->get();

        $summary = [
            'by_status' => [], 'by_purpose' => [], 'by_provider' => [],
            // Cash and online are always published, even at zero, so the register
            // never renders a missing collected figure.
            'by_method' => [
                'cash' => ['count' => 0, 'amount_minor' => 0],
                'gateway' => ['count' => 0, 'amount_minor' => 0],
            ],
            'totals' => ['receipts' => 0, 'amount_minor' => 0, 'succeeded_minor' => 0],
        ];

        foreach ($rows as $row) {
            $amount = Money::minor($row->amount);
            $count = (int) $row->receipts;
            $this->accumulate($summary['by_status'], $row->status, $count, $amount);
            $summary['totals']['receipts'] += $count;
            $summary['totals']['amount_minor'] += $amount;

            // Only succeeded money is reported as received. Pending, failed and
            // cancelled amounts appear in by_status and never in a collected figure.
            if ($row->status === 'succeeded') {
                $this->accumulate($summary['by_method'], $row->payment_method, $count, $amount);
                $this->accumulate($summary['by_provider'], $row->provider ?: 'cash', $count, $amount);
                $this->accumulate($summary['by_purpose'], $row->payment_purpose, $count, $amount);
                $summary['totals']['succeeded_minor'] += $amount;
            }
        }

        $summary['totals']['amount'] = Money::decimal($summary['totals']['amount_minor']);
        $summary['totals']['succeeded_amount'] = Money::decimal($summary['totals']['succeeded_minor']);
        unset($summary['totals']['amount_minor'], $summary['totals']['succeeded_minor']);

        foreach (['by_status', 'by_method', 'by_provider', 'by_purpose'] as $group) {
            foreach ($summary[$group] as $key => $values) {
                $summary[$group][$key] = ['count' => $values['count'], 'amount' => Money::decimal($values['amount_minor'])];
            }
        }

        return $summary;
    }

    public function exportRows(array $filters, ReportPeriod $period, bool $withContacts = false): LazyCollection
    {
        $query = $this->ledger->applyFilters($this->ledger->baseQuery(), $filters, $period);

        return $query->orderBy('pt.id')->cursor()
            ->map(fn (object $row) => $this->ledger->row($row, $withContacts))
            ->take(50000);
    }

    private function accumulate(array &$bucket, ?string $key, int $count, int $amount): void
    {
        $key = $key ?: 'unspecified';
        $bucket[$key] ??= ['count' => 0, 'amount_minor' => 0];
        $bucket[$key]['count'] += $count;
        $bucket[$key]['amount_minor'] += $amount;
    }
}
