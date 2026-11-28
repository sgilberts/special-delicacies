<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\PaystackSettlementException;
use App\Http\Controllers\Controller;
use App\Services\Paystack\PaystackSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stage 4D-PAYSTACK: read-only Paystack settlement retrieval and preview.
 *
 * Every endpoint is a GET except the audited connection test. Nothing here
 * allocates, receipts, refunds, posts fees or closes a reconciliation run.
 */
class PaystackSettlementController extends Controller
{
    public function __construct(private readonly PaystackSettlementService $paystack) {}

    public function status(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->paystack->status()]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->paystack->testConnection($request->user())]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:success,processing,pending,failed'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $data = $this->paystack->settlements($validated);
        } catch (PaystackSettlementException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true] + $data);
    }

    public function show(string $settlement): JsonResponse
    {
        try {
            $data = $this->paystack->settlement($settlement);
        } catch (PaystackSettlementException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'data' => $data, 'read_only' => true]);
    }

    public function transactions(string $settlement): JsonResponse
    {
        try {
            $data = $this->paystack->transactions($settlement);
        } catch (PaystackSettlementException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true] + $data);
    }

    public function preview(Request $request, string $settlement): JsonResponse
    {
        try {
            $data = $this->paystack->preview($settlement, $request->user());
        } catch (PaystackSettlementException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function failure(PaystackSettlementException $exception): JsonResponse
    {
        $status = match ($exception->state()) {
            PaystackSettlementException::STATE_NOT_CONFIGURED => 409,
            PaystackSettlementException::STATE_AUTHENTICATION_FAILED,
            PaystackSettlementException::STATE_INVALID_RESPONSE => 502,
            PaystackSettlementException::STATE_RATE_LIMITED,
            PaystackSettlementException::STATE_PROVIDER_UNAVAILABLE => 503,
            default => 422,
        };

        return response()->json([
            'success' => false,
            'state' => $exception->state(),
            'message' => $exception->getMessage(),
        ], $status);
    }
}
