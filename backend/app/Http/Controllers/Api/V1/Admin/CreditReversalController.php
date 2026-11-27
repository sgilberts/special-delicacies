<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomerCreditReversalService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditReversalController extends Controller
{
    public function __construct(private readonly CustomerCreditReversalService $reversals) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:requested,approved,rejected'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'organisation_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->reversals->requests($validated);

        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->meta($page)]);
    }

    public function eligible(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'organisation_id' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->reversals->eligibleEntries($validated);

        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->meta($page)]);
    }

    public function preview(int $ledgerEntry): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->reversals->preview($ledgerEntry)]);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ledger_entry_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'operation_reference' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        try {
            $reversal = $this->reversals->initiate(
                (int) $validated['ledger_entry_id'], $validated['amount'], $validated['operation_reference'], $validated['reason'], $request->user()
            );
            return response()->json(['success' => true, 'data' => [
                'id' => $reversal->id, 'status' => $reversal->status, 'operation_reference' => $reversal->operation_reference,
                'amount' => $reversal->amount, 'currency' => $reversal->currency, 'reason' => $reversal->reason,
            ]], 201);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function approve(int $creditReversal): JsonResponse
    {
        try {
            $reversal = $this->reversals->approve($creditReversal, request()->user());
            return response()->json(['success' => true, 'data' => [
                'id' => $reversal->id, 'status' => $reversal->status, 'amount' => $reversal->amount,
                'currency' => $reversal->currency, 'reversal_ledger_entry_id' => $reversal->reversal_ledger_entry_id,
                'approved_at' => $reversal->approved_at,
            ]]);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function reject(Request $request, int $creditReversal): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        try {
            $reversal = $this->reversals->reject($creditReversal, $validated['reason'], $request->user());
            return response()->json(['success' => true, 'data' => [
                'id' => $reversal->id, 'status' => $reversal->status, 'rejection_reason' => $reversal->rejection_reason,
            ]]);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    private function conflict(DomainException $exception): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
    }

    private function meta($page): array
    {
        return [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ];
    }
}
