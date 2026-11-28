<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReconciliationService;
use App\Services\Reporting\ReportPeriod;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Stage 4D reconciliation: internal receipt/invoice reconciliation and auditable runs. */
class FinancialReconciliationController extends Controller
{
    public function __construct(private readonly ReconciliationService $reconciliation) {}

    public function show(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $period = ReportPeriod::fromFilters($filters);

        return response()->json([
            'success' => true,
            'data' => $this->reconciliation->snapshot($filters, $period),
            'meta' => ['date_basis' => 'receipt date for receipts; invoice issue date for invoices'],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function settlements(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->reconciliation->settlementAvailability()]);
    }

    public function runs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:draft,in_progress,awaiting_review,reconciled,reconciled_with_exceptions'],
            'source' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->reconciliation->runs($validated);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
            ],
        ]);
    }

    public function prepare(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $period = ReportPeriod::fromFilters($filters);

        try {
            $run = $this->reconciliation->prepare($request->user(), $filters, $period);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $run], 201);
    }

    public function review(Request $request, int $run): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:reconciled,reconciled_with_exceptions'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $reviewed = $this->reconciliation->review($request->user(), $run, $validated['status'], $validated['resolution_notes'] ?? null);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $reviewed]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
    }
}
