<?php

namespace App\Services\Paystack;

use App\Models\PaymentTransaction;
use App\Services\Reporting\Money;
use Illuminate\Support\Carbon;

/**
 * Read-only comparison of one Paystack settlement against local receipts.
 *
 * The preview only reads: it never allocates, receipts, reverses, issues credit,
 * processes a refund, writes a fee to the ledger or closes a reconciliation run.
 * Nothing it returns is persisted (decision D13 pending), so a generated preview
 * is a working view, not a reconciliation record.
 */
final class PaystackSettlementReconciliationPreviewService
{
    public const CLASSIFICATIONS = [
        'exact_match' => 'Exact match',
        'possible_match' => 'Possible match',
        'amount_mismatch' => 'Amount mismatch',
        'currency_mismatch' => 'Currency mismatch',
        'provider_only' => 'Provider transaction without a local record',
        'local_only' => 'Local successful payment absent from the settlement',
        'duplicate_provider_transaction' => 'Duplicate provider transaction',
        'other_settlement' => 'Already included in another settlement',
        'refund_or_reversal' => 'Refund or reversal requiring separate interpretation',
        'undetermined' => 'Unable to determine',
    ];

    /** Keep the preview payload bounded; the scope is reported back to the caller. */
    private const LOCAL_LIMIT = 500;

    /**
     * @param  array<string, mixed>  $settlement  mapped settlement
     * @param  array<int, array<string, mixed>>  $transactions  mapped transactions
     * @return array<string, mixed>
     */
    public function build(array $settlement, array $transactions, string $source): array
    {
        $settlementId = (string) ($settlement['id'] ?? '');
        $currency = $settlement['currency'] ?? null;

        [$locals, $truncated] = $this->locals($settlement, $transactions);

        $byProviderReference = [];
        $byTransactionReference = [];
        foreach ($locals as $local) {
            if ($local->provider_reference !== null && $local->provider_reference !== '') {
                $byProviderReference[(string) $local->provider_reference] ??= $local;
            }
            $byTransactionReference[(string) $local->transaction_reference] ??= $local;
        }

        $matchedLocalIds = [];
        $seenReferences = [];
        $seenIds = [];
        $rows = [];
        $counts = array_fill_keys(array_keys(self::CLASSIFICATIONS), 0);

        foreach ($transactions as $transaction) {
            $row = $this->classify(
                $transaction,
                $settlementId,
                $currency,
                $locals,
                $byProviderReference,
                $byTransactionReference,
                $matchedLocalIds,
                $seenReferences,
                $seenIds,
            );

            $counts[$row['classification']]++;
            $rows[] = $row;
        }

        foreach ($locals as $local) {
            if (isset($matchedLocalIds[$local->id])) {
                continue;
            }

            $counts['local_only']++;
            $rows[] = [
                'classification' => 'local_only',
                'classification_label' => self::CLASSIFICATIONS['local_only'],
                'explanation' => 'A successful Paystack receipt on this settlement date is not present in the provider settlement.',
                'reference' => $local->provider_reference ?: $local->transaction_reference,
                'provider' => null,
                'local' => $this->localShape($local),
                'difference' => null,
            ];
        }

        $providerTotal = 0;
        $matchedLocalTotal = 0;
        foreach ($rows as $row) {
            $providerMinor = $row['provider']['amount']['minor'] ?? null;
            $localMinor = $row['local']['amount']['minor'] ?? null;

            // A duplicate is one transaction, and a transaction the provider
            // attributes to another settlement is not part of this batch total.
            if ($providerMinor !== null
                && ($row['provider']['currency_supported'] ?? false)
                && ! in_array($row['classification'], ['duplicate_provider_transaction', 'other_settlement'], true)) {
                $providerTotal += $providerMinor;
            }
            if ($localMinor !== null && in_array($row['classification'], ['exact_match', 'possible_match'], true)) {
                $matchedLocalTotal += $localMinor;
            }
        }

        return [
            'settlement' => $settlement,
            'currency' => $currency,
            'provider_source' => $source,
            'summary' => [
                'provider_transactions' => count($transactions),
                'local_records' => count($locals),
                'classifications' => $counts,
                'provider_total' => ['minor' => $providerTotal, 'decimal' => Money::decimal($providerTotal)],
                'matched_local_total' => ['minor' => $matchedLocalTotal, 'decimal' => Money::decimal($matchedLocalTotal)],
                'difference' => ['minor' => $providerTotal - $matchedLocalTotal, 'decimal' => Money::decimal($providerTotal - $matchedLocalTotal)],
                'quarantined' => $counts['currency_mismatch'],
                'needs_attention' => count($rows) - $counts['exact_match'],
            ],
            'local_scope' => [
                'provider' => 'paystack',
                'status' => 'succeeded',
                'currency' => $currency,
                'date_basis' => 'received_at on the settlement date',
                'limit' => self::LOCAL_LIMIT,
                'truncated' => $truncated,
            ],
            'rows' => $rows,
            'read_only' => true,
            'mutations_applied' => false,
            'restrictions' => [
                'D5' => 'Provider fee fields are shown only as reported. No fee is posted to the ledger, derived or edited.',
                'D12' => 'No manual matching confirmation or second-approver threshold exists in this preview.',
                'D13' => 'Nothing in this preview is persisted: no settlement or statement rows are stored.',
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<int, PaymentTransaction>  $locals
     * @param  array<string, PaymentTransaction>  $byProviderReference
     * @param  array<string, PaymentTransaction>  $byTransactionReference
     * @param  array<int, int>  $matchedLocalIds
     * @param  array<string, bool>  $seenReferences
     * @param  array<string, bool>  $seenIds
     * @return array<string, mixed>
     */
    private function classify(
        array $transaction,
        string $settlementId,
        ?string $currency,
        array $locals,
        array $byProviderReference,
        array $byTransactionReference,
        array &$matchedLocalIds,
        array &$seenReferences,
        array &$seenIds,
    ): array {
        $reference = $transaction['reference'] ?? null;
        $providerId = $transaction['id'] ?? null;
        $providerAmount = $transaction['amount'] ?? null;

        $base = [
            'reference' => $reference,
            'provider' => $this->providerShape($transaction),
            'local' => null,
            'difference' => null,
        ];

        if (($reference === null || $reference === '') && ($providerId === null || $providerId === '')) {
            return $this->row('undetermined', 'The provider transaction carries no identifier this integration can match on.', $base);
        }

        if ($reference !== null && $reference !== '' && isset($seenReferences[$reference])) {
            return $this->row('duplicate_provider_transaction', 'The same provider reference appears more than once in this settlement.', $base);
        }
        if ($providerId !== null && $providerId !== '' && isset($seenIds[$providerId])) {
            return $this->row('duplicate_provider_transaction', 'The same provider transaction identifier appears more than once in this settlement.', $base);
        }

        if ($reference !== null && $reference !== '') {
            $seenReferences[$reference] = true;
        }
        if ($providerId !== null && $providerId !== '') {
            $seenIds[$providerId] = true;
        }

        if ($this->isRefundOrReversal($transaction)) {
            return $this->row('refund_or_reversal', 'The provider reports a refunded, reversed or negative transaction; it needs separate interpretation before it can be matched.', $base);
        }

        $providerSettlement = $transaction['settlement'] ?? null;
        if ($providerSettlement !== null && $providerSettlement !== '' && $settlementId !== '' && $providerSettlement !== $settlementId) {
            return $this->row('other_settlement', "The provider reports this transaction under settlement {$providerSettlement}, not {$settlementId}.", $base);
        }

        if (! ($transaction['currency_supported'] ?? false)) {
            return $this->row(
                'currency_mismatch',
                'The provider reports '.($transaction['currency'] ?? 'an unknown currency').' for this transaction; it is quarantined and never converted to '.($currency ?? 'the settlement currency').'.',
                $base,
            );
        }

        $local = $this->findLocal($transaction, $byProviderReference, $byTransactionReference);
        $matchedBy = $local === null ? null : 'reference';

        if ($local === null) {
            $local = $this->findByAmountAndDate($transaction, $locals, $matchedLocalIds);
            $matchedBy = $local === null ? null : 'amount_and_date';
        }

        if ($local === null) {
            return $this->row('provider_only', 'No local receipt matches this provider reference.', $base);
        }

        $matchedLocalIds[$local->id] = $local->id;
        $base['local'] = $this->localShape($local);
        $base['difference'] = $this->difference($providerAmount, $local->amount);

        $localCurrency = strtoupper((string) $local->currency);
        if ($localCurrency !== strtoupper((string) $transaction['currency'])) {
            return $this->row('currency_mismatch', "The local receipt is in {$localCurrency} but the provider reports {$transaction['currency']}; no conversion is applied.", $base);
        }

        $providerMinor = $providerAmount['minor'] ?? null;
        $localMinor = Money::minor($local->amount);
        if ($providerMinor !== null && $providerMinor !== $localMinor) {
            return $this->row('amount_mismatch', 'The provider amount differs from the local receipt amount.', $base);
        }

        if ($local->status !== 'succeeded') {
            return $this->row('possible_match', "The reference matches, but the local receipt status is {$local->status} rather than succeeded.", $base);
        }

        if ($matchedBy === 'amount_and_date') {
            return $this->row('possible_match', 'No shared reference exists; the transaction matches a local receipt on amount and payment date only.', $base);
        }

        return $this->row('exact_match', 'Reference, currency, amount and local status all agree.', $base);
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, PaymentTransaction>  $byProviderReference
     * @param  array<string, PaymentTransaction>  $byTransactionReference
     */
    private function findLocal(array $transaction, array $byProviderReference, array $byTransactionReference): ?PaymentTransaction
    {
        $reference = $transaction['reference'] ?? null;
        if ($reference === null || $reference === '') {
            return null;
        }

        return $byProviderReference[$reference] ?? $byTransactionReference[$reference] ?? null;
    }

    /**
     * Weakest permitted match: only used when no reference is shared.
     *
     * @param  array<string, mixed>  $transaction
     * @param  array<int, PaymentTransaction>  $locals
     * @param  array<int, int>  $matchedLocalIds
     */
    private function findByAmountAndDate(array $transaction, array $locals, array $matchedLocalIds): ?PaymentTransaction
    {
        $providerMinor = $transaction['amount']['minor'] ?? null;
        $paidDate = $this->dateOf($transaction['paid_at'] ?? null);

        if ($providerMinor === null || $paidDate === null) {
            return null;
        }

        foreach ($locals as $local) {
            if (isset($matchedLocalIds[$local->id]) || $local->status !== 'succeeded') {
                continue;
            }
            if (Money::minor($local->amount) !== $providerMinor) {
                continue;
            }
            if ($this->dateOf($local->received_at?->toIso8601String()) === $paidDate) {
                return $local;
            }
        }

        return null;
    }

    /**
     * Local receipts that could appear in this settlement: successful Paystack
     * receipts in the settlement currency, on the settlement date, plus any
     * receipt whose reference the provider returned.
     *
     * @param  array<string, mixed>  $settlement
     * @param  array<int, array<string, mixed>>  $transactions
     * @return array{0: array<int, PaymentTransaction>, 1: bool}
     */
    private function locals(array $settlement, array $transactions): array
    {
        $references = array_values(array_filter(array_map(
            fn (array $transaction): ?string => $transaction['reference'] ?? null,
            $transactions,
        )));

        $query = PaymentTransaction::query()
            ->when($references !== [], fn ($builder) => $builder->where(
                fn ($nested) => $nested
                    ->whereIn('provider_reference', $references)
                    ->orWhereIn('transaction_reference', $references)
            ));

        $settlementDate = $this->dateOf($settlement['settlement_date'] ?? null);
        $currency = $settlement['currency'] ?? null;

        if ($settlementDate !== null && $currency !== null) {
            $start = Carbon::parse($settlementDate)->startOfDay();
            $end = Carbon::parse($settlementDate)->endOfDay();

            $query->orWhere(fn ($builder) => $builder
                ->where('provider', 'paystack')
                ->where('status', 'succeeded')
                ->where('currency', $currency)
                ->whereBetween('received_at', [$start, $end]));
        }

        if ($references === [] && $settlementDate === null) {
            return [[], false];
        }

        $records = $query->orderBy('id')->limit(self::LOCAL_LIMIT + 1)->get();

        return [$records->take(self::LOCAL_LIMIT)->all(), $records->count() > self::LOCAL_LIMIT];
    }

    /** @param  array<string, mixed>  $transaction */
    private function isRefundOrReversal(array $transaction): bool
    {
        if (in_array(strtolower((string) ($transaction['status'] ?? '')), ['reversed', 'refunded', 'refund'], true)) {
            return true;
        }

        return ($transaction['amount']['minor'] ?? 0) < 0;
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    private function providerShape(array $transaction): array
    {
        return [
            'id' => $transaction['id'] ?? null,
            'reference' => $transaction['reference'] ?? null,
            'status' => $transaction['status'] ?? null,
            'currency' => $transaction['currency'] ?? null,
            'currency_supported' => (bool) ($transaction['currency_supported'] ?? false),
            'amount' => $transaction['amount'] ?? null,
            'fees' => $transaction['fees'] ?? null,
            'channel' => $transaction['channel'] ?? null,
            'paid_at' => $transaction['paid_at'] ?? null,
            'settlement' => $transaction['settlement'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function localShape(PaymentTransaction $local): array
    {
        return [
            'id' => $local->id,
            'transaction_reference' => $local->transaction_reference,
            'provider_reference' => $local->provider_reference,
            'status' => $local->status,
            'currency' => $local->currency,
            'amount' => ['minor' => Money::minor($local->amount), 'decimal' => Money::decimal(Money::minor($local->amount))],
            'received_at' => $local->received_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{minor:int, decimal:string}|null  $providerAmount
     * @return array{minor:int, decimal:string}|null
     */
    private function difference(?array $providerAmount, ?string $localAmount): ?array
    {
        if ($providerAmount === null || $localAmount === null) {
            return null;
        }

        $minor = $providerAmount['minor'] - Money::minor($localAmount);

        return ['minor' => $minor, 'decimal' => Money::decimal($minor)];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function row(string $classification, string $explanation, array $base): array
    {
        return [
            'classification' => $classification,
            'classification_label' => self::CLASSIFICATIONS[$classification],
            'explanation' => $explanation,
            'reference' => $base['reference'],
            'provider' => $base['provider'],
            'local' => $base['local'],
            'difference' => $base['difference'],
        ];
    }

    private function dateOf(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
