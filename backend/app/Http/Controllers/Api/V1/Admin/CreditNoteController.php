<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommercialDocument;
use App\Services\CreditNoteService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditNoteController extends Controller
{
    public function __construct(private readonly CreditNoteService $creditNotes) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:draft,issued,voided'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->creditNotes->list($validated);

        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->meta($page)]);
    }

    public function eligibleInvoices(Request $request): JsonResponse
    {
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->creditNotes->eligibleInvoices($validated);

        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->meta($page)]);
    }

    public function show(int $creditNote): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->creditNotes->show($creditNote)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'originating_document_id' => ['required', 'integer', 'min:1'],
            'amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.description' => ['required_with:lines', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,3})?$/'],
            'lines.*.unit_price' => ['required_with:lines', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.discount_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.tax_rate' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
        ]);
        try {
            $note = $this->creditNotes->create($request->user(), $validated, $request);
            return response()->json(['success' => true, 'data' => $this->creditNotes->show($note->id)], 201);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function issue(Request $request, CommercialDocument $creditNote): JsonResponse
    {
        try {
            $this->creditNotes->issue($request->user(), $creditNote, $request);
            return response()->json(['success' => true, 'data' => $this->creditNotes->show($creditNote->id)]);
        } catch (DomainException $exception) {
            return $this->conflict($exception);
        }
    }

    public function void(Request $request, CommercialDocument $creditNote): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        try {
            $this->creditNotes->void($request->user(), $creditNote, $validated['reason'], $request);
            return response()->json(['success' => true, 'data' => $this->creditNotes->show($creditNote->id)]);
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
