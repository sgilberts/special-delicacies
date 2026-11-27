<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\RefundService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'refund_method' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->refunds->list($validated);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->pageMeta($page)]);
    }

    public function eligiblePayments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->refunds->eligiblePayments($validated);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->pageMeta($page)]);
    }

    public function payment(int $paymentId): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->refunds->paymentContext($paymentId)]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(Refund $refund): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->refunds->present($refund)]);
    }

    public function history(Refund $refund): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->refunds->history($refund)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_transaction_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'refund_method' => ['required', 'in:original_payment_method,customer_credit,manual'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'commercial_document_id' => ['nullable', 'integer', 'min:1'],
            'payment_allocation_id' => ['nullable', 'integer', 'min:1'],
            'operation_reference' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        try {
            $refund = $this->refunds->create($request->user(), $validated, $request);
            return response()->json(['success' => true, 'data' => $this->refunds->present($refund)], 201);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    public function submit(Request $request, Refund $refund): JsonResponse
    {
        return $this->act(fn () => $this->refunds->submit($request->user(), $refund, $request));
    }

    public function review(Request $request, Refund $refund): JsonResponse
    {
        return $this->act(fn () => $this->refunds->review($request->user(), $refund, $request));
    }

    public function approve(Request $request, Refund $refund): JsonResponse
    {
        return $this->act(fn () => $this->refunds->approve($request->user(), $refund, $request));
    }

    public function reject(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return $this->act(fn () => $this->refunds->reject($request->user(), $refund, $validated['reason'], $request));
    }

    public function process(Request $request, Refund $refund): JsonResponse
    {
        return $this->act(fn () => $this->refunds->process($request->user(), $refund, $request));
    }

    public function complete(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate(['evidence_reference' => ['nullable', 'string', 'max:150']]);
        return $this->act(fn () => $this->refunds->complete($request->user(), $refund, $validated['evidence_reference'] ?? null, $request));
    }

    public function fail(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return $this->act(fn () => $this->refunds->fail($request->user(), $refund, $validated['reason'], $request));
    }

    public function cancel(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return $this->act(fn () => $this->refunds->cancel($request->user(), $refund, $validated['reason'], $request));
    }

    public function reverse(Request $request, Refund $refund): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return $this->act(fn () => $this->refunds->reverse($request->user(), $refund, $validated['reason'], $request));
    }

    public function verify(Request $request, Refund $refund): JsonResponse
    {
        return $this->act(fn () => $this->refunds->verifyGateway($request->user(), $refund, $request));
    }

    public function retry(Request $request, Refund $refund): JsonResponse
    {
        return $this->act(fn () => $this->refunds->retry($request->user(), $refund, $request));
    }

    private function act(callable $operation): JsonResponse
    {
        try {
            $refund = $operation();
            return response()->json(['success' => true, 'data' => $this->refunds->present($refund)]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    private function pageMeta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
