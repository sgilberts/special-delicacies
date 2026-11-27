<?php

namespace App\Services;

use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Models\CustomerCreditLedgerEntry;
use App\Models\Organisation;
use App\Models\PaymentReceiptIntent;
use App\Models\PaymentTransaction;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CustomerCreditService
{
    public function __construct(private readonly PaymentAllocationService $allocations) {}

    public function eligibleReceipts(array $filters = [])
    {
        $query = PaymentTransaction::query()
            ->with(['customer:id,name,email,phone', 'organisation:id,name,reference', 'receiptIntent'])
            ->where('payment_purpose', 'invoice')->whereNull('order_id')
            ->where('status', 'succeeded')->whereNotNull('verified_at')->whereNotNull('received_at')
            ->where(function (Builder $payer): void {
                $payer->where(fn (Builder $q) => $q->whereNotNull('customer_id')->whereNull('organisation_id'))
                    ->orWhere(fn (Builder $q) => $q->whereNull('customer_id')->whereNotNull('organisation_id'));
            })
            ->whereRaw("payment_transactions.amount > COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payment_allocation_operations po ON po.id = pa.payment_allocation_operation_id LEFT JOIN payment_allocation_reversals pr ON pr.payment_allocation_id = pa.id WHERE po.payment_transaction_id = payment_transactions.id AND pr.id IS NULL), 0) + COALESCE((SELECT SUM(ce.amount) FROM customer_credit_ledger_entries ce WHERE ce.source_payment_transaction_id = payment_transactions.id AND ce.entry_type = 'designation'), 0) - COALESCE((SELECT SUM(rev.amount) FROM customer_credit_ledger_entries rev JOIN customer_credit_ledger_entries orig ON orig.id = rev.reverses_entry_id WHERE orig.source_payment_transaction_id = payment_transactions.id AND orig.entry_type = 'designation' AND rev.entry_type = 'reversal'), 0)")
            ->orderByDesc('received_at')->orderByDesc('id');

        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn (Builder $q) => $q->where('transaction_reference', 'like', $term)
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $term)->orWhere('email', 'like', $term))
                ->orWhereHas('organisation', fn (Builder $o) => $o->where('name', 'like', $term)->orWhere('reference', 'like', $term)));
        }

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 20), 100)))
            ->through(fn (PaymentTransaction $payment) => $this->receiptData($payment));
    }

    public function eligibleInvoices(array $filters = [])
    {
        $query = CommercialDocument::query()->with(['customer:id,name', 'organisation:id,name'])
            ->where('document_type', CommercialDocument::TYPE_INVOICE)
            ->where('status', CommercialDocument::STATUS_ISSUED)->whereNotNull('issued_at')->whereNull('voided_at')->where('total', '>', 0)
            ->whereRaw("commercial_documents.total > COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa LEFT JOIN payment_allocation_reversals pr ON pr.payment_allocation_id = pa.id WHERE pa.commercial_document_id = commercial_documents.id AND pr.id IS NULL), 0) + COALESCE((SELECT SUM(ce.amount) FROM customer_credit_ledger_entries ce WHERE ce.commercial_document_id = commercial_documents.id AND ce.entry_type = 'application'), 0)")
            ->orderByDesc('issue_date')->orderByDesc('id');
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn (Builder $q) => $q->where('document_number', 'like', $term)
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $term))
                ->orWhereHas('organisation', fn (Builder $o) => $o->where('name', 'like', $term)));
        }
        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 30), 100)))
            ->through(fn (CommercialDocument $document) => [
                'id' => $document->id, 'document_number' => $document->document_number,
                'payer' => $document->organisation ? ['type' => 'organisation', 'id' => $document->organisation->id, 'name' => $document->organisation->name] : ['type' => 'customer', 'id' => $document->customer?->id, 'name' => $document->customer?->name],
                'currency' => $document->currency, 'total' => $document->total,
                'payment_summary' => $this->allocations->balance($document),
            ]);
    }

    public function classifyReceipt(int $paymentId, string $intent, string $operationReference, string $reason, User $actor): PaymentReceiptIntent
    {
        if (! in_array($intent, ['deposit', 'advance'], true)) throw new DomainException('Choose deposit or advance payment intent.');
        $this->assertReference($operationReference);

        return DB::transaction(function () use ($paymentId, $intent, $operationReference, $reason, $actor): PaymentReceiptIntent {
            $payment = PaymentTransaction::query()->lockForUpdate()->find($paymentId);
            if (! $payment) throw new DomainException('Receipt not found.');
            $this->assertEligibleReceipt($payment);
            if ($this->allocations->availableMinorForPayment($payment->id) <= 0) throw new DomainException('The receipt has no unallocated balance.');
            $existing = PaymentReceiptIntent::query()->where('payment_transaction_id', $payment->id)->first();
            if ($existing) {
                if ($existing->intent === $intent && $existing->operation_reference === $operationReference
                    && trim($existing->reason) === trim($reason) && (int) $existing->actor_user_id === (int) $actor->id) return $existing;
                throw new DomainException('This receipt already has a deposit or advance designation.');
            }
            // operation_reference is unique across all receipt intents; reject a
            // reference reused by another receipt instead of surfacing a database error.
            if (PaymentReceiptIntent::query()->where('operation_reference', $operationReference)->exists()) {
                throw new DomainException('This operation reference was already used for another receipt designation.');
            }
            return PaymentReceiptIntent::query()->create([
                'payment_transaction_id' => $payment->id, 'intent' => $intent,
                'operation_reference' => $operationReference, 'reason' => trim($reason),
                'actor_user_id' => $actor->id, 'actor_user_id_snapshot' => $actor->id,
                'actor_name_snapshot' => $actor->name, 'actor_reference_snapshot' => 'user:'.$actor->id,
                'designated_at' => now(),
            ]);
        }, 3);
    }

    public function designateCredit(int $paymentId, string $amount, string $operationReference, string $reason, User $actor): CustomerCreditLedgerEntry
    {
        $minor = PaymentAllocationService::toMinorUnits($amount);
        if ($minor <= 0) throw new DomainException('Credit designation must be greater than zero.');
        $this->assertReference($operationReference);

        return DB::transaction(function () use ($paymentId, $minor, $operationReference, $reason, $actor): CustomerCreditLedgerEntry {
            $payment = PaymentTransaction::query()->lockForUpdate()->find($paymentId);
            if (! $payment) throw new DomainException('Receipt not found.');
            $this->assertEligibleReceipt($payment);
            if (! $payment->receiptIntent()->exists()) throw new DomainException('Classify the receipt as a deposit or advance before designating credit.');

            $payer = $this->payerFor($payment);
            $this->lockPayer($payer);
            $existing = CustomerCreditLedgerEntry::query()->where('operation_reference', $operationReference)->first();
            if ($existing) {
                $this->assertSameOperation($existing, CustomerCreditLedgerEntry::TYPE_DESIGNATION, $payment->id, null, $payer, $payment->currency, $minor, $reason, $actor);
                return $existing;
            }

            $available = $this->allocations->availableMinorForPayment($payment->id);
            if ($minor > $available) throw new DomainException('Credit designation exceeds the receipt’s unallocated balance.');
            $creditBefore = $this->creditBalanceMinor($payer, $payment->currency, true);
            return CustomerCreditLedgerEntry::query()->create($this->entryData(
                CustomerCreditLedgerEntry::TYPE_DESIGNATION, $operationReference, $payer, $payment->currency,
                $minor, $reason, $actor, $creditBefore, $creditBefore + $minor,
                $payment->id, null, $available, $available - $minor, null, null,
            ));
        }, 3);
    }

    public function applyCredit(int $documentId, string $amount, string $operationReference, string $reason, User $actor): CustomerCreditLedgerEntry
    {
        $minor = PaymentAllocationService::toMinorUnits($amount);
        if ($minor <= 0) throw new DomainException('Credit application must be greater than zero.');
        $this->assertReference($operationReference);
        $candidate = CommercialDocument::query()->findOrFail($documentId);
        $payer = $this->payerFor($candidate);

        return DB::transaction(function () use ($documentId, $minor, $operationReference, $reason, $actor, $payer): CustomerCreditLedgerEntry {
            $this->lockPayer($payer);
            $document = CommercialDocument::query()->lockForUpdate()->find($documentId);
            if (! $document) throw new DomainException('Invoice not found.');
            $this->assertInvoicePayer($document, $payer);
            $existing = CustomerCreditLedgerEntry::query()->where('operation_reference', $operationReference)->first();
            if ($existing) {
                $this->assertSameOperation($existing, CustomerCreditLedgerEntry::TYPE_APPLICATION, null, $document->id, $payer, $document->currency, $minor, $reason, $actor);
                return $existing;
            }

            $creditBefore = $this->creditBalanceMinor($payer, $document->currency, true);
            $outstanding = $this->allocations->invoiceOutstandingMinor($document);
            if ($minor > $creditBefore) throw new DomainException('Credit application exceeds the available credit balance.');
            if ($minor > $outstanding) throw new DomainException('Credit application exceeds the invoice outstanding balance.');
            return CustomerCreditLedgerEntry::query()->create($this->entryData(
                CustomerCreditLedgerEntry::TYPE_APPLICATION, $operationReference, $payer, $document->currency,
                $minor, $reason, $actor, $creditBefore, $creditBefore - $minor,
                null, $document->id, null, null, $outstanding, $outstanding - $minor,
            ));
        }, 3);
    }

    public function balanceMinor(Customer|Organisation $payer, string $currency): int
    {
        $base = CustomerCreditLedgerEntry::query()->where('currency', $currency);
        $payer instanceof Customer ? $base->where('customer_id', $payer->id)->whereNull('organisation_id') : $base->where('organisation_id', $payer->id)->whereNull('customer_id');
        $credit = (clone $base)->whereIn('entry_type', CustomerCreditLedgerEntry::CREDIT_TYPES)->sum('amount');
        $used = (clone $base)->whereIn('entry_type', CustomerCreditLedgerEntry::DEBIT_TYPES)->sum('amount');
        return PaymentAllocationService::toMinorUnits((string) ($credit ?: '0')) - PaymentAllocationService::toMinorUnits((string) ($used ?: '0'));
    }

    /** Expose the canonical derived balance to other Stage 4C services. */
    public function currentBalanceMinor(Customer|Organisation $payer, string $currency, bool $forUpdate = false): int
    {
        return $this->creditBalanceMinor($payer, $currency, $forUpdate);
    }

    public function customerCreditView(Customer $customer, array $organisationIds = []): array
    {
        $accounts = [[
            'payer' => ['type' => 'customer', 'id' => $customer->id, 'name' => $customer->name],
            'entries' => $this->ledgerQuery($customer, null)->get(),
        ]];
        foreach (Organisation::query()->whereIn('id', $organisationIds)->orderBy('id')->get() as $organisation) {
            $accounts[] = [
                'payer' => ['type' => 'organisation', 'id' => $organisation->id, 'name' => $organisation->name],
                'entries' => $this->ledgerQuery(null, $organisation)->get(),
            ];
        }
        return ['accounts' => array_map(fn (array $account) => $this->accountData($account['payer'], $account['entries']), $accounts)];
    }

    public function staffLedger(array $filters = [])
    {
        $query = CustomerCreditLedgerEntry::query()->with(['customer:id,name,email,phone', 'organisation:id,name,reference', 'sourcePayment:id,transaction_reference', 'commercialDocument:id,document_number', 'reversesEntry:id,operation_reference', 'actor:id,name'])
            ->latest('created_at')->latest('id');
        if (isset($filters['customer_id'])) $query->where('customer_id', $filters['customer_id']);
        if (isset($filters['organisation_id'])) $query->where('organisation_id', $filters['organisation_id']);
        if (isset($filters['currency'])) $query->where('currency', $filters['currency']);
        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->through(fn (CustomerCreditLedgerEntry $entry) => $this->entryView($entry));
    }

    public function staffBalances(): array
    {
        return CustomerCreditLedgerEntry::query()->select(['customer_id', 'organisation_id', 'currency'])
            ->groupBy('customer_id', 'organisation_id', 'currency')->orderBy('currency')->get()
            ->map(function (CustomerCreditLedgerEntry $entry): array {
                $payer = $entry->customer_id !== null
                    ? Customer::query()->find($entry->customer_id)
                    : Organisation::query()->find($entry->organisation_id);
                if (! $payer) return [];
                $isCustomer = $payer instanceof Customer;
                return [
                    'payer' => ['type' => $isCustomer ? 'customer' : 'organisation', 'id' => $payer->id, 'name' => $payer->name],
                    'currency' => $entry->currency,
                    'available_balance' => PaymentAllocationService::formatMinorUnits($this->creditBalanceMinor($payer, $entry->currency)),
                ];
            })->filter()->values()->all();
    }

    private function ledgerQuery(?Customer $customer, ?Organisation $organisation)
    {
        $query = CustomerCreditLedgerEntry::query()->with(['sourcePayment:id,transaction_reference', 'commercialDocument:id,document_number', 'reversesEntry:id,operation_reference', 'actor:id,name'])->latest('created_at')->latest('id');
        $customer ? $query->where('customer_id', $customer->id)->whereNull('organisation_id') : $query->where('organisation_id', $organisation?->id)->whereNull('customer_id');
        return $query;
    }

    private function accountData(array $payer, $entries): array
    {
        $currencies = $entries->pluck('currency')->unique()->sort()->values();
        return [
            'payer' => $payer,
            'balances' => $currencies->map(fn (string $currency) => ['currency' => $currency, 'available_balance' => PaymentAllocationService::formatMinorUnits($this->creditBalanceMinorFromPayer($payer, $currency))])->values(),
            'history' => $entries->map(fn (CustomerCreditLedgerEntry $entry) => $this->entryView($entry))->values(),
        ];
    }

    private function creditBalanceMinorFromPayer(array $payer, string $currency): int
    {
        $model = $payer['type'] === 'customer' ? Customer::find($payer['id']) : Organisation::find($payer['id']);
        return $model ? $this->creditBalanceMinor($model, $currency) : 0;
    }

    private function entryView(CustomerCreditLedgerEntry $entry): array
    {
        return [
            'id' => $entry->id, 'type' => $entry->entry_type, 'amount' => $entry->amount, 'currency' => $entry->currency,
            'reference' => $entry->operation_reference, 'reason' => $entry->reason, 'created_at' => $entry->created_at,
            'source_reference' => $entry->sourcePayment?->transaction_reference,
            'invoice_number' => $entry->commercialDocument?->document_number,
            'actor' => $entry->actor_name_snapshot ?: $entry->actor?->name ?: 'Unknown / unavailable',
            'reverses_entry_id' => $entry->reverses_entry_id,
            'reversal_of_reference' => $entry->reversesEntry?->operation_reference,
            'authorized_by' => $entry->authorized_by_name_snapshot ?: $entry->authorizedBy?->name,
            'authorized_at' => $entry->authorized_at,
            'credit_balance_before' => $entry->credit_balance_before, 'credit_balance_after' => $entry->credit_balance_after,
        ];
    }

    private function receiptData(PaymentTransaction $payment): array
    {
        return [
            'id' => $payment->id, 'reference' => $payment->transaction_reference,
            'payer' => $payment->organisation ? ['type' => 'organisation', 'id' => $payment->organisation->id, 'name' => $payment->organisation->name] : ['type' => 'customer', 'id' => $payment->customer->id, 'name' => $payment->customer->name],
            'amount' => $payment->amount, 'currency' => $payment->currency, 'intent' => $payment->receiptIntent?->intent,
            'reason' => $payment->receiptIntent?->reason, 'verified_at' => $payment->verified_at, 'received_at' => $payment->received_at,
            'unallocated_amount' => PaymentAllocationService::formatMinorUnits($this->allocations->availableMinorForPayment($payment->id)),
        ];
    }

    private function assertEligibleReceipt(PaymentTransaction $payment): void
    {
        if ($payment->payment_purpose !== 'invoice' || $payment->order_id !== null || $payment->status !== 'succeeded' || ! $payment->verified_at || ! $payment->received_at) {
            throw new DomainException('Only standalone verified successful invoice-purpose receipts can be designated.');
        }
        if (($payment->customer_id !== null) === ($payment->organisation_id !== null)) throw new DomainException('The receipt must have exactly one identified payer.');
    }

    private function payerFor(PaymentTransaction|CommercialDocument $source): Customer|Organisation
    {
        if ($source->organisation_id !== null) return Organisation::query()->findOrFail($source->organisation_id);
        if ($source->customer_id !== null) return Customer::query()->findOrFail($source->customer_id);
        throw new DomainException('A customer or organisation payer is required.');
    }

    private function assertInvoicePayer(CommercialDocument $document, Customer|Organisation $payer): void
    {
        if ($document->document_type !== CommercialDocument::TYPE_INVOICE || $document->status !== CommercialDocument::STATUS_ISSUED || ! $document->issued_at || $document->voided_at || $document->total <= 0) {
            throw new DomainException('Only issued, non-voided invoices can receive credit.');
        }
        $samePayer = $payer instanceof Customer
            ? $document->customer_id === $payer->id && $document->organisation_id === null
            : $document->organisation_id === $payer->id && $document->customer_id === null;
        if (! $samePayer) throw new DomainException('Credit owner does not match the invoice payer.');
    }

    private function lockPayer(Customer|Organisation $payer): void
    {
        $table = $payer instanceof Customer ? 'customers' : 'organisations';
        if (! DB::table($table)->where('id', $payer->id)->lockForUpdate()->first()) throw new DomainException('Credit payer no longer exists.');
    }

    private function creditBalanceMinor(Customer|Organisation $payer, string $currency, bool $forUpdate = false): int
    {
        $query = CustomerCreditLedgerEntry::query()->where('currency', $currency);
        $payer instanceof Customer ? $query->where('customer_id', $payer->id)->whereNull('organisation_id') : $query->where('organisation_id', $payer->id)->whereNull('customer_id');
        if ($forUpdate) {
            // InnoDB REPEATABLE READ may otherwise reuse a snapshot established before
            // the payer lock was acquired. A locking read observes the latest committed
            // ledger entries after another operation releases that payer lock.
            $credits = 0;
            $applications = 0;
            foreach ($query->orderBy('id')->lockForUpdate()->get(['entry_type', 'amount']) as $entry) {
                $minor = PaymentAllocationService::toMinorUnits((string) $entry->amount);
                in_array($entry->entry_type, CustomerCreditLedgerEntry::CREDIT_TYPES, true)
                    ? $credits += $minor
                    : $applications += $minor;
            }
            return $credits - $applications;
        }
        $credits = (clone $query)->whereIn('entry_type', CustomerCreditLedgerEntry::CREDIT_TYPES)->sum('amount');
        $applications = (clone $query)->whereIn('entry_type', CustomerCreditLedgerEntry::DEBIT_TYPES)->sum('amount');
        return PaymentAllocationService::toMinorUnits((string) ($credits ?: '0')) - PaymentAllocationService::toMinorUnits((string) ($applications ?: '0'));
    }

    private function entryData(string $type, string $reference, Customer|Organisation $payer, string $currency, int $amount, string $reason, User $actor, int $creditBefore, int $creditAfter, ?int $paymentId, ?int $documentId, ?int $paymentBefore, ?int $paymentAfter, ?int $invoiceBefore, ?int $invoiceAfter): array
    {
        return [
            'entry_type' => $type, 'operation_reference' => $reference,
            'source_payment_transaction_id' => $paymentId, 'commercial_document_id' => $documentId,
            'customer_id' => $payer instanceof Customer ? $payer->id : null,
            'organisation_id' => $payer instanceof Organisation ? $payer->id : null,
            'actor_user_id' => $actor->id, 'actor_user_id_snapshot' => $actor->id,
            'actor_name_snapshot' => $actor->name, 'actor_reference_snapshot' => 'user:'.$actor->id,
            'amount' => PaymentAllocationService::formatMinorUnits($amount), 'currency' => $currency, 'reason' => trim($reason),
            'credit_balance_before' => PaymentAllocationService::formatMinorUnits($creditBefore),
            'credit_balance_after' => PaymentAllocationService::formatMinorUnits($creditAfter),
            'payment_unallocated_before' => $paymentBefore === null ? null : PaymentAllocationService::formatMinorUnits($paymentBefore),
            'payment_unallocated_after' => $paymentAfter === null ? null : PaymentAllocationService::formatMinorUnits($paymentAfter),
            'invoice_outstanding_before' => $invoiceBefore === null ? null : PaymentAllocationService::formatMinorUnits($invoiceBefore),
            'invoice_outstanding_after' => $invoiceAfter === null ? null : PaymentAllocationService::formatMinorUnits($invoiceAfter),
            'created_at' => now(),
        ];
    }

    private function assertSameOperation(CustomerCreditLedgerEntry $entry, string $type, ?int $paymentId, ?int $documentId, Customer|Organisation $payer, string $currency, int $amount, string $reason, User $actor): void
    {
        $paymentMatches = $paymentId === null ? $entry->source_payment_transaction_id === null : (int) $entry->source_payment_transaction_id === $paymentId;
        $documentMatches = $documentId === null ? $entry->commercial_document_id === null : (int) $entry->commercial_document_id === $documentId;
        $payerMatches = $payer instanceof Customer
            ? (int) $entry->customer_id === (int) $payer->id && $entry->organisation_id === null
            : (int) $entry->organisation_id === (int) $payer->id && $entry->customer_id === null;
        if ($entry->entry_type !== $type || ! $paymentMatches || ! $documentMatches
            || ! $payerMatches || $entry->currency !== $currency || PaymentAllocationService::toMinorUnits((string) $entry->amount) !== $amount
            || trim($entry->reason) !== trim($reason) || (int) $entry->actor_user_id !== (int) $actor->id) {
            throw new DomainException('This operation reference was already used for a different credit operation.');
        }
    }

    private function assertReference(string $reference): void
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $reference)) throw new DomainException('The operation reference is invalid.');
    }
}
