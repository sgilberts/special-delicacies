<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FIFO attribution of credit-ledger consumption.
 *
 * This mirrors CustomerCreditReversalService::remainingReversibleMinor() exactly:
 * a reversal reduces the specific credit entry it reverses, and applications are
 * consumed oldest-credit-first. The parity test (Stage4dReportingKernelTest)
 * asserts that summing the remaining per entry equals the derived ledger balance.
 */
final class CreditAttribution
{
    public const CREDIT_TYPES = ['designation', 'credit_note_credit'];

    /**
     * @return array<int, int> credit ledger entry id => remaining minor units
     */
    public function remainingByEntry(string $payerType, int $payerId, string $currency, ?CarbonImmutable $asOf = null): array
    {
        $column = $payerType === 'organisation' ? 'organisation_id' : 'customer_id';

        $query = DB::table('customer_credit_ledger_entries')
            ->where($column, $payerId)
            ->where('currency', $currency)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($asOf !== null) {
            $query->where('created_at', '<=', $asOf->format('Y-m-d H:i:s'));
        }

        $entries = $query->get(['id', 'entry_type', 'amount', 'reverses_entry_id']);

        $credits = [];
        $reversed = [];
        $consumption = 0;

        foreach ($entries as $entry) {
            if (in_array($entry->entry_type, self::CREDIT_TYPES, true)) {
                $credits[] = $entry;
            } elseif ($entry->entry_type === 'reversal') {
                if ($entry->reverses_entry_id !== null) {
                    $id = (int) $entry->reverses_entry_id;
                    $reversed[$id] = ($reversed[$id] ?? 0) + Money::minor($entry->amount);
                } else {
                    $consumption += Money::minor($entry->amount);
                }
            } elseif ($entry->entry_type === 'application') {
                $consumption += Money::minor($entry->amount);
            }
        }

        $remaining = [];
        foreach ($credits as $credit) {
            $available = max(0, Money::minor($credit->amount) - ($reversed[(int) $credit->id] ?? 0));
            $take = min($available, $consumption);
            $consumption -= $take;
            $remaining[(int) $credit->id] = $available - $take;
        }

        return $remaining;
    }

    public function remainingForEntry(int $entryId, string $payerType, int $payerId, string $currency): int
    {
        return $this->remainingByEntry($payerType, $payerId, $currency)[$entryId] ?? 0;
    }
}
