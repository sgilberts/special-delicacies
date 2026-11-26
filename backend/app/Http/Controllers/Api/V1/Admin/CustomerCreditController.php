<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentReceiptIntent;
use App\Services\CustomerCreditService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerCreditController extends Controller
{
    public function __construct(private readonly CustomerCreditService $credit) {}

    public function receipts(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->credit->eligibleReceipts($validated);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->credit->eligibleInvoices($validated);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function classify(Request $request, int $paymentId): JsonResponse
    {
        $validated = $request->validate([
            'intent' => ['required', 'in:deposit,advance'],
            'operation_reference' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        try {
            $intent = $this->credit->classifyReceipt($paymentId, $validated['intent'], $validated['operation_reference'], $validated['reason'], $request->user());
            return response()->json(['success' => true, 'data' => [
                'payment_transaction_id' => $intent->payment_transaction_id, 'intent' => $intent->intent,
                'operation_reference' => $intent->operation_reference, 'reason' => $intent->reason,
                'actor' => $intent->actor_name_snapshot, 'designated_at' => $intent->designated_at,
            ]], 201);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    public function designate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_transaction_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'operation_reference' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        try {
            $entry = $this->credit->designateCredit((int) $validated['payment_transaction_id'], $validated['amount'], $validated['operation_reference'], $validated['reason'], $request->user());
            return response()->json(['success' => true, 'data' => $this->entryData($entry)], 201);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'commercial_document_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'operation_reference' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        try {
            $entry = $this->credit->applyCredit((int) $validated['commercial_document_id'], $validated['amount'], $validated['operation_reference'], $validated['reason'], $request->user());
            return response()->json(['success' => true, 'data' => $this->entryData($entry)], 201);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    public function ledger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'min:1'], 'organisation_id' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->credit->staffLedger($validated);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function balances(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->credit->staffBalances()]);
    }

    private function entryData($entry): array
    {
        return [
            'id' => $entry->id, 'entry_type' => $entry->entry_type, 'operation_reference' => $entry->operation_reference,
            'source_payment_transaction_id' => $entry->source_payment_transaction_id, 'commercial_document_id' => $entry->commercial_document_id,
            'customer_id' => $entry->customer_id, 'organisation_id' => $entry->organisation_id, 'amount' => $entry->amount,
            'currency' => $entry->currency, 'reason' => $entry->reason, 'actor' => $entry->actor_name_snapshot,
            'credit_balance_before' => $entry->credit_balance_before, 'credit_balance_after' => $entry->credit_balance_after,
            'payment_unallocated_before' => $entry->payment_unallocated_before, 'payment_unallocated_after' => $entry->payment_unallocated_after,
            'invoice_outstanding_before' => $entry->invoice_outstanding_before, 'invoice_outstanding_after' => $entry->invoice_outstanding_after,
            'created_at' => $entry->created_at,
        ];
    }
}
