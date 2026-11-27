<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\DepositService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Customer-initiated online deposits. Every response is scoped to the
 * authenticated customer (or an organisation they hold financial access to);
 * no client-supplied status, amount, currency or payer is trusted.
 */
class CustomerDepositController extends Controller
{
    public function __construct(private readonly DepositService $deposits) {}

    public function options(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        return response()->json(['success' => true, 'data' => $this->deposits->customerOptions($customer)]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        return response()->json(['success' => true, 'data' => $this->deposits->customerHistory($customer)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payer_type' => ['required', 'in:customer,organisation'],
            'organisation_id' => ['nullable', 'integer', 'min:1'],
            'intent' => ['required', 'in:deposit,advance'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'payment_gateway_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        try {
            $deposit = $this->deposits->initiateOnline($request->user(), $validated);
            return response()->json(['success' => true, 'data' => $deposit], 201);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->deposits->customerDeposit($request->user(), $reference)]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 404);
        }
    }

    /** Return-from-gateway handler: server-side verification only. */
    public function verify(Request $request, string $reference): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->deposits->customerDeposit($request->user(), $reference, true)]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 404);
        }
    }
}
