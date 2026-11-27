<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PaymentTransaction;
use App\Models\StaffDepositRecord;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Deposit origination for the two authorised Stage 4B workflows:
 *
 *  - customer-initiated online deposits through an existing payment gateway;
 *  - staff-recorded deposits for money received outside the online checkout,
 *    requiring independent evidence-based verification by a second staff member.
 *
 * Both write the same canonical records: one payment_transactions row, one
 * payment_receipt_intents classification (written on trusted completion only)
 * and the existing append-only customer credit ledger.
 */
class DepositService
{
    public const INTENTS = ['deposit', 'advance'];

    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other'];

    public function __construct(
        private readonly GatewayPaymentService $gateways,
        private readonly DepositReceiptService $receipts,
        private readonly OrganisationAuthorization $organisations,
    ) {}

    // ---------------------------------------------------------------- customer

    public function customerOptions(Customer $customer): array
    {
        $payers = [[
            'type' => 'customer', 'id' => $customer->id, 'name' => $customer->name,
        ]];
        foreach ($this->financialOrganisations($customer) as $organisation) {
            $payers[] = ['type' => 'organisation', 'id' => $organisation->id, 'name' => $organisation->name];
        }

        return [
            'payers' => $payers,
            'gateways' => $this->gateways->availableGateways(),
            'intents' => self::INTENTS,
            'currency' => 'GHS',
        ];
    }

    public function customerHistory(Customer $customer): array
    {
        return $this->customerDepositQuery($customer)
            ->with(['receiptIntent', 'staffDepositRecord', 'customer:id,name', 'organisation:id,name'])
            ->withExists('creditDesignations')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get()
            ->map(fn (PaymentTransaction $transaction): array => $this->depositView($transaction))
            ->all();
    }

    public function customerDeposit(Customer $customer, string $reference, bool $verify = true): array
    {
        $transaction = $this->customerDepositQuery($customer)->where('transaction_reference', $reference)->first();
        if (! $transaction) {
            throw new DomainException('Deposit not found.');
        }

        if ($verify && $transaction->status === 'pending' && filled($transaction->provider)) {
            try {
                $this->gateways->verifyDepositCharge($transaction);
            } catch (\Illuminate\Http\Client\ConnectionException) {
                // The provider could not be reached: report the authoritative
                // local state instead of inventing a successful payment.
            }
            $transaction = $this->customerDepositQuery($customer)
                ->with(['receiptIntent', 'staffDepositRecord', 'customer:id,name', 'organisation:id,name'])
                ->find($transaction->id);
        }

        return $this->depositView($transaction->load(['receiptIntent', 'staffDepositRecord', 'customer:id,name', 'organisation:id,name']));
    }

    public function initiateOnline(Customer $customer, array $data): array
    {
        $intent = (string) $data['intent'];
        if (! in_array($intent, self::INTENTS, true)) {
            throw new DomainException('Choose deposit or advance payment intent.');
        }
        $amountMinor = PaymentAllocationService::toMinorUnits((string) $data['amount']);
        if ($amountMinor <= 0) {
            throw new DomainException('Enter a deposit amount greater than zero.');
        }

        [$customerId, $organisationId] = $this->resolveOnlinePayer($customer, (string) $data['payer_type'], $data['organisation_id'] ?? null);
        $gateway = $this->gateways->depositGateway((int) $data['payment_gateway_id']);
        $reference = $this->depositReference($customerId, $organisationId, (string) $data['idempotency_key']);

        $transaction = PaymentTransaction::query()->where('transaction_reference', $reference)->first();
        if ($transaction) {
            if ((int) $transaction->customer_id !== (int) $customerId
                || (int) $transaction->organisation_id !== (int) $organisationId
                || PaymentAllocationService::toMinorUnits((string) $transaction->amount) !== $amountMinor
                || data_get($transaction->metadata, 'intent') !== $intent) {
                throw new DomainException('This deposit request key was already used for a different deposit.');
            }
            // A replay of the same request returns the existing deposit instead
            // of creating a second receipt or a second gateway charge.
            if ($transaction->status !== 'pending' || filled(data_get($transaction->metadata, 'checkout_url'))) {
                return $this->depositView($transaction->load(['receiptIntent', 'staffDepositRecord', 'customer:id,name', 'organisation:id,name']));
            }
        } else {
            try {
                $transaction = PaymentTransaction::create([
                    'order_id' => null,
                    'customer_id' => $customerId,
                    'organisation_id' => $organisationId,
                    'payment_purpose' => 'invoice',
                    'transaction_reference' => $reference,
                    'provider' => $gateway->provider,
                    'payment_method' => 'gateway',
                    'amount' => PaymentAllocationService::formatMinorUnits($amountMinor),
                    'currency' => 'GHS',
                    'status' => 'pending',
                    'reporting_date' => now()->toDateString(),
                    'metadata' => [
                        'source' => 'customer_online_deposit',
                        'intent' => $intent,
                        'idempotency_key' => (string) $data['idempotency_key'],
                        'gateway_id' => $gateway->id,
                    ],
                ]);
            } catch (QueryException $exception) {
                if (! $this->isDuplicateKey($exception)) throw $exception;
                throw new DomainException('This deposit request was already submitted. Refresh your deposit history.');
            }
        }

        try {
            $checkoutUrl = $this->gateways->initializeDepositCharge($transaction, $gateway, $customer);
        } catch (\Throwable $exception) {
            $this->receipts->fail($transaction, 'failed');
            throw new RuntimeException($exception->getMessage());
        }

        $transaction->forceFill([
            'metadata' => array_merge($transaction->metadata ?? [], ['checkout_url' => $checkoutUrl]),
        ])->save();

        return $this->depositView($transaction->fresh(['receiptIntent', 'staffDepositRecord', 'customer:id,name', 'organisation:id,name']));
    }

    // ------------------------------------------------------------------ staff

    public function recordStaffDeposit(User $actor, array $data): StaffDepositRecord
    {
        $intent = (string) $data['intent'];
        if (! in_array($intent, self::INTENTS, true)) {
            throw new DomainException('Choose deposit or advance payment intent.');
        }
        $amountMinor = PaymentAllocationService::toMinorUnits((string) $data['amount']);
        if ($amountMinor <= 0) {
            throw new DomainException('Enter a deposit amount greater than zero.');
        }
        $currency = strtoupper((string) ($data['currency'] ?? 'GHS'));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('Enter a valid three-letter currency.');
        }
        $method = (string) $data['payment_method'];
        if (! in_array($method, self::PAYMENT_METHODS, true)) {
            throw new DomainException('Choose the payment source for this deposit.');
        }
        $external = trim((string) $data['external_reference']);
        if (mb_strlen($external) < 4) {
            throw new DomainException('Provide the external receipt reference.');
        }
        $evidence = trim((string) $data['evidence']);
        if (mb_strlen($evidence) < 10) {
            throw new DomainException('Describe the supporting evidence for this receipt.');
        }

        [$customerId, $organisationId] = $this->resolveStaffPayer((string) $data['payer_type'], $data['customer_id'] ?? null, $data['organisation_id'] ?? null);

        if (StaffDepositRecord::query()->where('payment_method', $method)->where('external_reference', $external)->exists()) {
            throw new DomainException('This external payment reference has already been recorded.');
        }

        try {
            return DB::transaction(function () use ($actor, $intent, $amountMinor, $currency, $method, $external, $evidence, $customerId, $organisationId): StaffDepositRecord {
                $transaction = PaymentTransaction::create([
                    'order_id' => null,
                    'customer_id' => $customerId,
                    'organisation_id' => $organisationId,
                    'payment_purpose' => 'invoice',
                    'transaction_reference' => 'DEP'.strtoupper(bin2hex(random_bytes(9))),
                    'provider' => null,
                    'payment_method' => $method,
                    'amount' => PaymentAllocationService::formatMinorUnits($amountMinor),
                    'currency' => $currency,
                    'status' => 'pending',
                    'reporting_date' => now()->toDateString(),
                    'metadata' => ['source' => 'staff_deposit', 'intent' => $intent],
                ]);

                return StaffDepositRecord::create([
                    'payment_transaction_id' => $transaction->id,
                    'intent' => $intent,
                    'operation_reference' => 'SDEP-'.strtoupper(Str::random(20)),
                    'external_reference' => $external,
                    'payment_method' => $method,
                    'evidence' => $evidence,
                    'status' => StaffDepositRecord::STATUS_PENDING,
                    'recorded_by' => $actor->id,
                    'recorded_by_snapshot' => $actor->id,
                    'recorded_by_name_snapshot' => $actor->name,
                    'recorded_at' => now(),
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw new DomainException('This external payment reference has already been recorded.');
            }
            throw $exception;
        }
    }

    /**
     * Independent verification decision. The recording staff member can never
     * approve their own deposit, and a receipt only becomes credit-eligible
     * once a different authorised staff member confirms the evidence.
     */
    public function decideStaffDeposit(User $actor, int $recordId, bool $approve, string $reason): StaffDepositRecord
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new DomainException('Record the reason for this verification decision.');
        }

        return DB::transaction(function () use ($actor, $recordId, $approve, $reason): StaffDepositRecord {
            $record = StaffDepositRecord::query()->lockForUpdate()->find($recordId);
            if (! $record) {
                throw new DomainException('Deposit record not found.');
            }
            if ($record->status === StaffDepositRecord::STATUS_VERIFIED) {
                if ($approve) return $record; // idempotent replay
                throw new DomainException('This deposit has already been verified.');
            }
            if ($record->status === StaffDepositRecord::STATUS_REJECTED) {
                if (! $approve) return $record; // idempotent replay
                throw new DomainException('A rejected deposit cannot be verified.');
            }
            if ((int) $record->recorded_by === (int) $actor->id) {
                throw new DomainException('A deposit must be verified by a different staff member from the one who recorded it.');
            }

            $transaction = PaymentTransaction::query()->lockForUpdate()->findOrFail($record->payment_transaction_id);
            if ($approve) {
                $this->receipts->complete($transaction, $record->intent, $actor, [
                    'name' => $actor->name,
                    'reference' => 'user:'.$actor->id,
                    'reason' => 'Verified staff-recorded '.$record->intent.'. External reference: '.$record->external_reference.'. '.$record->evidence,
                ]);
            } else {
                $this->receipts->fail($transaction, 'cancelled');
            }

            $record->forceFill([
                'status' => $approve ? StaffDepositRecord::STATUS_VERIFIED : StaffDepositRecord::STATUS_REJECTED,
                'verified_by' => $actor->id,
                'verified_by_snapshot' => $actor->id,
                'verified_by_name_snapshot' => $actor->name,
                'verified_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            return $record->fresh(['paymentTransaction']);
        }, 3);
    }

    public function staffDeposits(array $filters = [])
    {
        $query = StaffDepositRecord::query()
            ->with([
                'paymentTransaction.customer:id,name,email,phone', 'paymentTransaction.organisation:id,name,reference',
                'recorder:id,name', 'verifier:id,name',
            ])
            ->latest('recorded_at')->latest('id');

        if (in_array($filters['status'] ?? null, [StaffDepositRecord::STATUS_PENDING, StaffDepositRecord::STATUS_VERIFIED, StaffDepositRecord::STATUS_REJECTED], true)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn ($search) => $search
                ->where('external_reference', 'like', $term)
                ->orWhere('operation_reference', 'like', $term)
                ->orWhereHas('paymentTransaction', fn ($payment) => $payment
                    ->where('transaction_reference', 'like', $term)
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $term)->orWhere('phone', 'like', $term))
                    ->orWhereHas('organisation', fn ($organisation) => $organisation->where('name', 'like', $term)->orWhere('reference', 'like', $term))));
        }

        return $query->paginate(max(1, min((int) ($filters['per_page'] ?? 20), 100)))
            ->through(fn (StaffDepositRecord $record): array => $this->staffRecordView($record));
    }

    public function staffDeposit(int $id): array
    {
        $record = StaffDepositRecord::query()
            ->with([
                'paymentTransaction.customer:id,name,email,phone', 'paymentTransaction.organisation:id,name,reference',
                'paymentTransaction.receiptIntent', 'recorder:id,name', 'verifier:id,name',
            ])->find($id);
        if (! $record) {
            throw new DomainException('Deposit record not found.');
        }
        return $this->staffRecordView($record);
    }

    public function searchPayers(string $term): array
    {
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($term)).'%';
        $customers = Customer::query()->where('is_active', true)
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'phone', 'email'])
            ->map(fn (Customer $customer): array => [
                'type' => 'customer', 'id' => $customer->id, 'name' => $customer->name,
                'detail' => trim($customer->phone.($customer->email ? ' · '.$customer->email : '')),
            ])->all();
        $organisations = Organisation::query()
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('reference', 'like', $like))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'reference'])
            ->map(fn (Organisation $organisation): array => [
                'type' => 'organisation', 'id' => $organisation->id, 'name' => $organisation->name,
                'detail' => (string) $organisation->reference,
            ])->all();

        return [...$customers, ...$organisations];
    }

    // --------------------------------------------------------------- internals

    private function customerDepositQuery(Customer $customer)
    {
        return PaymentTransaction::query()
            ->whereNull('order_id')
            ->where('payment_purpose', 'invoice')
            ->where(function ($payer) use ($customer): void {
                $payer->where(fn ($own) => $own->where('customer_id', $customer->id)->whereNull('organisation_id'))
                    ->orWhereIn('organisation_id', $this->financialOrganisationIds($customer));
            })
            ->where(fn ($origin) => $origin
                ->where('metadata->source', 'customer_online_deposit')
                ->orWhere('metadata->source', 'staff_deposit')
                ->orWhereHas('receiptIntent'));
    }

    /** @return array{0: ?int, 1: ?int} */
    private function resolveOnlinePayer(Customer $customer, string $payerType, mixed $organisationId): array
    {
        if ($payerType === 'customer') {
            return [$customer->id, null];
        }
        if ($payerType !== 'organisation') {
            throw new DomainException('Choose the payer for this deposit.');
        }
        $organisation = Organisation::find((int) $organisationId);
        if (! $organisation || ! $this->organisations->allows($customer, $organisation, 'financials.view')) {
            throw new DomainException('You do not have financial access to that organisation.');
        }
        return [null, $organisation->id];
    }

    /** @return array{0: ?int, 1: ?int} */
    private function resolveStaffPayer(string $payerType, mixed $customerId, mixed $organisationId): array
    {
        if ($payerType === 'customer') {
            if (! Customer::query()->whereKey((int) $customerId)->exists()) {
                throw new DomainException('Choose a valid customer payer.');
            }
            return [(int) $customerId, null];
        }
        if ($payerType === 'organisation') {
            if (! Organisation::query()->whereKey((int) $organisationId)->exists()) {
                throw new DomainException('Choose a valid organisation payer.');
            }
            return [null, (int) $organisationId];
        }
        throw new DomainException('Choose the payer for this deposit.');
    }

    /**
     * Deterministic, unguessable reference: replaying the same request key for
     * the same payer can never create a second receipt.
     */
    private function depositReference(?int $customerId, ?int $organisationId, string $key): string
    {
        $payer = $customerId !== null ? 'customer:'.$customerId : 'organisation:'.$organisationId;
        return 'DEP'.strtoupper(substr(hash_hmac('sha256', $payer.'|'.$key, (string) config('app.key')), 0, 20));
    }

    /** @return int[] */
    private function financialOrganisationIds(Customer $customer): array
    {
        return $this->financialOrganisations($customer)->pluck('id')->all();
    }

    private function financialOrganisations(Customer $customer)
    {
        return Organisation::query()
            ->where('status', Organisation::STATUS_ACTIVE)
            ->whereIn('id', OrganisationMembership::query()
                ->where('customer_id', $customer->id)
                ->where('status', OrganisationMembership::STATUS_ACTIVE)
                ->whereHas('permissions', fn ($query) => $query->where('permission', 'financials.view'))
                ->select('organisation_id'))
            ->orderBy('name')->get();
    }

    private function depositView(PaymentTransaction $transaction): array
    {
        return [
            'reference' => $transaction->transaction_reference,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'intent' => $transaction->receiptIntent?->intent ?? data_get($transaction->metadata, 'intent'),
            'status' => $transaction->status,
            'origin' => data_get($transaction->metadata, 'source', 'receipt'),
            'payment_method' => $transaction->payment_method,
            'provider' => $transaction->provider,
            'payer' => $this->payerArray($transaction),
            'created_at' => $transaction->created_at,
            'verified_at' => $transaction->verified_at,
            'received_at' => $transaction->received_at,
            'checkout_url' => $transaction->status === 'pending' ? data_get($transaction->metadata, 'checkout_url') : null,
            'external_reference' => $transaction->staffDepositRecord?->external_reference,
            'credited' => (bool) ($transaction->credit_designations_exists ?? $transaction->creditDesignations()->exists()),
        ];
    }

    private function staffRecordView(StaffDepositRecord $record): array
    {
        $transaction = $record->paymentTransaction;
        return [
            'id' => $record->id,
            'status' => $record->status,
            'intent' => $record->intent,
            'external_reference' => $record->external_reference,
            'operation_reference' => $record->operation_reference,
            'payment_method' => $record->payment_method,
            'evidence' => $record->evidence,
            'amount' => $transaction?->amount,
            'currency' => $transaction?->currency,
            'payment_reference' => $transaction?->transaction_reference,
            'payment_status' => $transaction?->status,
            'payer' => $transaction ? $this->payerArray($transaction) : null,
            'recorded_by' => [
                'id' => $record->recorded_by_snapshot,
                'name' => $record->recorded_by_name_snapshot,
            ],
            'recorded_at' => $record->recorded_at,
            'verified_by' => $record->verified_by_snapshot ? [
                'id' => $record->verified_by_snapshot,
                'name' => $record->verified_by_name_snapshot,
            ] : null,
            'verified_at' => $record->verified_at,
            'decision_reason' => $record->decision_reason,
            'can_verify' => $record->status === StaffDepositRecord::STATUS_PENDING,
        ];
    }

    private function payerArray(PaymentTransaction $transaction): ?array
    {
        if ($transaction->organisation_id !== null) {
            return [
                'type' => 'organisation', 'id' => (int) $transaction->organisation_id,
                'name' => $transaction->organisation?->name ?? 'Organisation',
            ];
        }
        if ($transaction->customer_id !== null) {
            return [
                'type' => 'customer', 'id' => (int) $transaction->customer_id,
                'name' => $transaction->customer?->name ?? 'Customer',
            ];
        }
        return null;
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        $message = $exception->getMessage();
        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'Unique constraint');
    }
}
