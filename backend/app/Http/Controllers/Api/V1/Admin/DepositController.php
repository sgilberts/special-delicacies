<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\DepositService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff-recorded deposits. Recording creates a pending receipt; a different
 * authorised staff member must confirm the evidence before the receipt can
 * become credit-eligible. The service layer enforces both rules, so a direct
 * API request cannot bypass them.
 */
class DepositController extends Controller
{
    public function __construct(private readonly DepositService $deposits) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,verified,rejected'],
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->deposits->staffDeposits($validated);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function payers(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        return response()->json(['success' => true, 'data' => $this->deposits->searchPayers($validated['search'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payer_type' => ['required', 'in:customer,organisation'],
            'customer_id' => ['nullable', 'integer', 'min:1', 'required_if:payer_type,customer'],
            'organisation_id' => ['nullable', 'integer', 'min:1', 'required_if:payer_type,organisation'],
            'intent' => ['required', 'in:deposit,advance'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_method' => ['required', 'in:cash,bank_transfer,mobile_money,cheque,card,other'],
            'external_reference' => ['required', 'string', 'min:4', 'max:120'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $record = $this->deposits->recordStaffDeposit($request->user(), $validated);
            return response()->json(['success' => true, 'data' => $this->deposits->staffDeposit($record->id)], 201);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(int $deposit): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->deposits->staffDeposit($deposit)]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 404);
        }
    }

    public function verify(Request $request, int $deposit): JsonResponse
    {
        return $this->decide($request, $deposit, true);
    }

    public function reject(Request $request, int $deposit): JsonResponse
    {
        return $this->decide($request, $deposit, false);
    }

    private function decide(Request $request, int $deposit, bool $approve): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        try {
            $record = $this->deposits->decideStaffDeposit($request->user(), $deposit, $approve, $validated['reason']);
            return response()->json(['success' => true, 'data' => $this->deposits->staffDeposit($record->id)]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }
}
