<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Reports E and F — Customer and Organisation Credit.
 *
 * The append-only credit ledger is the only source of truth. Balances are
 * always derived (credits - applications - reversals), never read from a stored
 * column, and ageing reuses the 4C FIFO attribution algorithm.
 */
final class CreditReportService
{
    public function __construct(private readonly CreditAttribution $attribution) {}

    public function portfolio(string $payerType, array $filters, ReportPeriod $period, bool $withContacts = false): LengthAwarePaginator
    {
        return $this->portfolioQuery($payerType, $filters, $period, $withContacts)
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (object $row) => $this->row($payerType, $row, $withContacts));
    }

    private function portfolioQuery(string $payerType, array $filters, ReportPeriod $period, bool $withContacts): Builder
    {
        $column = $this->column($payerType);
        $table = $payerType === 'organisation' ? 'organisations' : 'customers';
        // Only organisations carry a business reference; customers do not.
        $reference = $payerType === 'organisation' ? 'payer.reference' : 'NULL';

        $pairs = DB::table('customer_credit_ledger_entries')
            ->whereNotNull($column)
            ->where('created_at', '<=', $period->end->format('Y-m-d H:i:s'))
            ->when(filled($filters['currency'] ?? null), fn (Builder $query) => $query->where('currency', $filters['currency']))
            ->select($column.' as payer_id', 'currency')
            ->distinct();

        $closing = $this->movementSub($column, $period->end->format('Y-m-d H:i:s'), '<=');
        $opening = $this->movementSub($column, $period->start->format('Y-m-d H:i:s'), '<');

        $query = DB::query()->fromSub($pairs, 'p')
            ->join($table.' as payer', 'payer.id', '=', 'p.payer_id')
            ->leftJoinSub($closing, 'closing', fn ($join) => $join->on('closing.payer_id', '=', 'p.payer_id')->on('closing.currency', '=', 'p.currency'))
            ->leftJoinSub($opening, 'opening', fn ($join) => $join->on('opening.payer_id', '=', 'p.payer_id')->on('opening.currency', '=', 'p.currency'))
            ->select([
                'p.payer_id', 'p.currency', 'payer.name as payer_name',
                DB::raw($reference.' as payer_reference'),
                'payer.email as payer_email', 'payer.phone as payer_phone',
                DB::raw('COALESCE(closing.credited, 0) as credited'),
                DB::raw('COALESCE(closing.designated, 0) as designated'),
                DB::raw('COALESCE(closing.credit_note_credit, 0) as credit_note_credit'),
                DB::raw('COALESCE(closing.applied, 0) as applied'),
                DB::raw('COALESCE(closing.reversed, 0) as reversed'),
                DB::raw('(COALESCE(closing.credited, 0) - COALESCE(closing.applied, 0) - COALESCE(closing.reversed, 0)) as closing_balance'),
                DB::raw('(COALESCE(opening.credited, 0) - COALESCE(opening.applied, 0) - COALESCE(opening.reversed, 0)) as opening_balance'),
                'closing.last_activity_at',
            ]);

        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn (Builder $search) => $search
                ->where('payer.name', 'like', $term)
                ->orWhere('payer.email', 'like', $term)
                ->when($payerType === 'organisation', fn (Builder $inner) => $inner->orWhere('payer.reference', 'like', $term)));
        }

        if (($filters['include_zero'] ?? '0') !== '1') {
            $query->whereRaw('(COALESCE(closing.credited, 0) - COALESCE(closing.applied, 0) - COALESCE(closing.reversed, 0)) <> 0');
        }

        return $query->orderByRaw('closing_balance DESC')->orderBy('payer.name');
    }

    public function totals(string $payerType, array $filters, ReportPeriod $period): array
    {
        $column = $this->column($payerType);

        $row = DB::table('customer_credit_ledger_entries')
            ->whereNotNull($column)
            ->where('created_at', '<=', $period->end->format('Y-m-d H:i:s'))
            ->when(filled($filters['currency'] ?? null), fn (Builder $query) => $query->where('currency', $filters['currency']))
            ->select(DB::raw(
                "SUM(CASE WHEN entry_type IN ('designation','credit_note_credit') THEN amount ELSE 0 END) as credited, "
                ."SUM(CASE WHEN entry_type = 'designation' THEN amount ELSE 0 END) as designated, "
                ."SUM(CASE WHEN entry_type = 'credit_note_credit' THEN amount ELSE 0 END) as credit_note_credit, "
                ."SUM(CASE WHEN entry_type = 'application' THEN amount ELSE 0 END) as applied, "
                ."SUM(CASE WHEN entry_type = 'reversal' THEN amount ELSE 0 END) as reversed, "
                ."COUNT(DISTINCT {$column}) as payers"
            ))->first();

        $credited = Money::minor($row->credited ?? 0);
        $applied = Money::minor($row->applied ?? 0);
        $reversed = Money::minor($row->reversed ?? 0);

        return [
            'payers' => (int) ($row->payers ?? 0),
            'credited' => Money::decimal($credited),
            'designated' => Money::decimal(Money::minor($row->designated ?? 0)),
            'credit_note_credit' => Money::decimal(Money::minor($row->credit_note_credit ?? 0)),
            'applied' => Money::decimal($applied),
            'reversed' => Money::decimal($reversed),
            'closing_balance' => Money::decimal($credited - $applied - $reversed),
        ];
    }

    public function statement(string $payerType, int $payerId, array $filters, ReportPeriod $period): array
    {
        $column = $this->column($payerType);
        $currency = (string) ($filters['currency'] ?? 'GHS');

        $entries = DB::table('customer_credit_ledger_entries as e')
            ->leftJoin('payment_transactions as pt', 'pt.id', '=', 'e.source_payment_transaction_id')
            ->leftJoin('commercial_documents as cd', 'cd.id', '=', 'e.commercial_document_id')
            ->where('e.'.$column, $payerId)
            ->where('e.currency', $currency)
            ->where('e.created_at', '<=', $period->end->format('Y-m-d H:i:s'))
            ->orderBy('e.created_at')->orderBy('e.id')
            ->select([
                'e.id', 'e.entry_type', 'e.operation_reference', 'e.amount', 'e.currency', 'e.reason',
                'e.credit_balance_before', 'e.credit_balance_after', 'e.actor_name_snapshot',
                'e.created_at', 'e.commercial_document_id', 'e.source_payment_transaction_id',
                'pt.transaction_reference', 'cd.document_number', 'cd.document_type',
            ])->get();

        $remaining = $this->attribution->remainingByEntry($payerType, $payerId, $currency, $period->end);

        $opening = 0;
        $movement = 0;
        $rows = [];

        foreach ($entries as $entry) {
            $amount = Money::minor($entry->amount);
            if ($entry->created_at < $period->start->format('Y-m-d H:i:s')) {
                $opening += in_array($entry->entry_type, CreditAttribution::CREDIT_TYPES, true) ? $amount : -$amount;
                continue;
            }

            $movement += in_array($entry->entry_type, CreditAttribution::CREDIT_TYPES, true) ? $amount : -$amount;
            $rows[] = [
                'id' => (int) $entry->id,
                'entry_type' => $entry->entry_type,
                'operation_reference' => $entry->operation_reference,
                'amount' => Money::decimal($amount),
                'currency' => $entry->currency,
                'reason' => $entry->reason,
                'actor' => $entry->actor_name_snapshot,
                'created_at' => $entry->created_at,
                'related_receipt' => $entry->transaction_reference,
                'related_invoice' => $entry->document_number,
                'related_invoice_type' => $entry->document_type,
                'balance_after' => $entry->credit_balance_after !== null ? Money::decimal(Money::minor($entry->credit_balance_after)) : null,
                'remaining_attributed' => isset($remaining[(int) $entry->id]) ? Money::decimal($remaining[(int) $entry->id]) : null,
            ];
        }

        return [
            'payer_type' => $payerType,
            'payer_id' => $payerId,
            'currency' => $currency,
            'period' => $period->meta('ledger created_at'),
            'opening_balance' => Money::decimal($opening),
            'movement' => Money::decimal($movement),
            'closing_balance' => Money::decimal($opening + $movement),
            'entries' => $rows,
        ];
    }

    public function exportRows(string $payerType, array $filters, ReportPeriod $period, bool $withContacts = false): LazyCollection
    {
        return $this->portfolioQuery($payerType, $filters, $period, $withContacts)->cursor()
            ->map(fn (object $row) => $this->row($payerType, $row, $withContacts))
            ->take(50000);
    }

    private function movementSub(string $column, string $cutoff, string $operator): Builder
    {
        return DB::table('customer_credit_ledger_entries')
            ->whereNotNull($column)
            ->where('created_at', $operator, $cutoff)
            ->groupBy($column, 'currency')
            ->selectRaw(
                $column.' as payer_id, currency, '
                ."SUM(CASE WHEN entry_type IN ('designation','credit_note_credit') THEN amount ELSE 0 END) as credited, "
                ."SUM(CASE WHEN entry_type = 'designation' THEN amount ELSE 0 END) as designated, "
                ."SUM(CASE WHEN entry_type = 'credit_note_credit' THEN amount ELSE 0 END) as credit_note_credit, "
                ."SUM(CASE WHEN entry_type = 'application' THEN amount ELSE 0 END) as applied, "
                ."SUM(CASE WHEN entry_type = 'reversal' THEN amount ELSE 0 END) as reversed, "
                .'MAX(created_at) as last_activity_at'
            );
    }

    private function row(string $payerType, object $row, bool $withContacts): array
    {
        $payer = [
            'type' => $payerType,
            'id' => (int) $row->payer_id,
            'name' => $row->payer_name,
            'reference' => $row->payer_reference,
        ];
        if ($withContacts) {
            $payer['email'] = $row->payer_email;
            $payer['phone'] = $row->payer_phone;
        }

        return [
            'payer' => $payer,
            'currency' => $row->currency,
            'opening_balance' => Money::decimal(Money::minor($row->opening_balance)),
            'credited' => Money::decimal(Money::minor($row->credited)),
            'designated' => Money::decimal(Money::minor($row->designated)),
            'credit_note_credit' => Money::decimal(Money::minor($row->credit_note_credit)),
            'applied' => Money::decimal(Money::minor($row->applied)),
            'reversed' => Money::decimal(Money::minor($row->reversed)),
            'closing_balance' => Money::decimal(Money::minor($row->closing_balance)),
            'last_activity_at' => $row->last_activity_at,
        ];
    }

    private function column(string $payerType): string
    {
        return $payerType === 'organisation' ? 'organisation_id' : 'customer_id';
    }
}
