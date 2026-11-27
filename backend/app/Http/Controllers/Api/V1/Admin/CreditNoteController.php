<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
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
            'status' => ['nullable', 'string', 'max:24'],
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->creditNotes->list($validated);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->pageMeta($page)]);
    }

    public function eligibleDocuments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->creditNotes->eligibleDocuments($validated);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->pageMeta($page)]);
    }

    public function document(int $commercialDocument): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->creditNotes->documentContext($commercialDocument)]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(CreditNote $creditNote): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->creditNotes->present($creditNote)]);
    }

    public function history(CreditNote $creditNote): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->creditNotes->history($creditNote)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedNote($request);
        try {
            $note = $this->creditNotes->create($request->user(), $validated, $request);
            return response()->json(['success' => true, 'data' => $this->creditNotes->present($note)], 201);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    public function update(Request $request, CreditNote $creditNote): JsonResponse
    {
        $validated = $this->validatedNote($request, false);
        try {
            $note = $this->creditNotes->updateDraft($request->user(), $creditNote, $validated, $request);
            return response()->json(['success' => true, 'data' => $this->creditNotes->present($note)]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    public function submit(Request $request, CreditNote $creditNote): JsonResponse
    {
        return $this->act(fn () => $this->creditNotes->submit($request->user(), $creditNote, $request));
    }

    public function approve(Request $request, CreditNote $creditNote): JsonResponse
    {
        return $this->act(fn () => $this->creditNotes->approve($request->user(), $creditNote, $request));
    }

    public function issue(Request $request, CreditNote $creditNote): JsonResponse
    {
        return $this->act(fn () => $this->creditNotes->issue($request->user(), $creditNote, $request));
    }

    public function apply(Request $request, CreditNote $creditNote): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'operation_reference' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        return $this->act(fn () => $this->creditNotes->apply($request->user(), $creditNote, $validated, $request));
    }

    public function cancel(Request $request, CreditNote $creditNote): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return $this->act(fn () => $this->creditNotes->cancel($request->user(), $creditNote, $validated['reason'], $request));
    }

    private function act(callable $operation): JsonResponse
    {
        try {
            $note = $operation();
            return response()->json(['success' => true, 'data' => $this->creditNotes->present($note)]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    private function pageMeta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    private function validatedNote(Request $request, bool $requireDocument = true): array
    {
        return $request->validate([
            'commercial_document_id' => [$requireDocument ? 'required' : 'sometimes', 'integer', 'min:1'],
            'reason' => [$requireDocument ? 'required' : 'sometimes', 'string', 'min:3', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'discount_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'discount_description' => ['nullable', 'string', 'max:255'],
            'lines' => [$requireDocument ? 'required' : 'sometimes', 'array', 'min:1', 'max:50'],
            'lines.*.commercial_document_line_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.detail' => ['nullable', 'string', 'max:2000'],
            'lines.*.unit_of_measure' => ['nullable', 'string', 'max:40'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,3})?$/'],
            'lines.*.unit_price' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.discount_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.tax_rate' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
        ]);
    }
}
