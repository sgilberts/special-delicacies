<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
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
            'status' => ['nullable', 'in:requested,approved,submitting,submitted,confirmed,rejected,failed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->refunds->list($validated);

        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->meta($page)]);
    }

    public function eligiblePayments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->refunds->eligiblePayments($validated);

        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->meta($page)]);
    }

    public function show(int $refund): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->refunds->show($refund)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_transaction_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'refund_reference' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        try {
            $refund = $this->refunds->create(
                (int) $validated['payment_transaction_id'], $validated['amount'], $validated['reason'], $validated['refund_reference'], $request->user()
            );
            return response()->json(['success' => true, 'data' => $this->refunds->show($refund->id)], 201);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function approve(Request $request, int $refund): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        try {
            $this->refunds->approve($refund, $request->user(), $validated['reason'] ?? null);
            return response()->json(['success' => true, 'data' => $this->refunds->show($refund)]);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function reject(Request $request, int $refund): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        try {
            $this->refunds->reject($refund, $request->user(), $validated['reason']);
            return response()->json(['success' => true, 'data' => $this->refunds->show($refund)]);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function submit(int $refund): JsonResponse
    {
        try {
            $this->refunds->submit($refund, request()->user());
            return response()->json(['success' => true, 'data' => $this->refunds->show($refund)]);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function confirm(Request $request, int $refund): JsonResponse
    {
        $validated = $request->validate([
            'provider_reference' => ['nullable', 'string', 'max:150'],
            'evidence' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $this->refunds->confirm($refund, $request->user(), $validated['provider_reference'] ?? null, $validated['evidence'] ?? null);
            return response()->json(['success' => true, 'data' => $this->refunds->show($refund)]);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function reconcile(Request $request, int $refund): JsonResponse
    {
        $validated = $request->validate(['confirm_absent' => ['nullable', 'boolean']]);
        try {
            $this->refunds->reconcile($refund, $request->user(), (bool) ($validated['confirm_absent'] ?? false));
            return response()->json(['success' => true, 'data' => $this->refunds->show($refund)]);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function fail(Request $request, int $refund): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        try {
            $this->refunds->fail($refund, $request->user(), $validated['reason']);
            return response()->json(['success' => true, 'data' => $this->refunds->show($refund)]);
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
