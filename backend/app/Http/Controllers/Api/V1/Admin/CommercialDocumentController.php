<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommercialDocument;
use App\Services\CommercialDocumentPresenter;
use App\Services\CommercialDocumentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CommercialDocumentController extends Controller
{
    public function __construct(private readonly CommercialDocumentService $documents, private readonly CommercialDocumentPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $query = CommercialDocument::query()->with(['customer:id,name,email,phone', 'organisation:id,name,reference']);
        foreach (['document_type', 'status', 'customer_id', 'organisation_id'] as $filter) {
            if ($request->filled($filter)) $query->where($filter, $request->query($filter));
        }
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->query('from'));
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->query('to'));
        $documents = $query->latest('id')->paginate(max(1, min((int) $request->query('per_page', 20), 100)));
        return response()->json([
            'success' => true,
            'data' => $documents->getCollection()->map(fn (CommercialDocument $document) => $this->present($document)),
            'meta' => ['current_page' => $documents->currentPage(), 'last_page' => $documents->lastPage(), 'per_page' => $documents->perPage(), 'total' => $documents->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(true));
        if ($validator->fails()) return $this->invalid($validator->errors()->toArray());
        try {
            $document = $this->documents->create($request->user(), $validator->validated(), $request);
            return response()->json(['success' => true, 'data' => $this->present($document)], 201);
        } catch (DomainException $exception) {
            return $this->conflict($exception->getMessage());
        }
    }

    public function show(CommercialDocument $commercialDocument): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->present($commercialDocument->load(['lines', 'customer', 'organisation']))]);
    }

    public function update(Request $request, CommercialDocument $commercialDocument): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(false));
        if ($validator->fails()) return $this->invalid($validator->errors()->toArray());
        try {
            $document = $this->documents->updateDraft($request->user(), $commercialDocument, $validator->validated(), $request);
            return response()->json(['success' => true, 'data' => $this->present($document)]);
        } catch (DomainException $exception) {
            return $this->conflict($exception->getMessage());
        }
    }

    public function issue(Request $request, CommercialDocument $commercialDocument): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->present($this->documents->issue($request->user(), $commercialDocument, $request))]);
        } catch (DomainException $exception) {
            return $this->conflict($exception->getMessage());
        }
    }

    public function cancel(Request $request, CommercialDocument $commercialDocument): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->present($this->documents->staffTransition($request->user(), $commercialDocument, 'cancel', null, $request))]);
        } catch (DomainException $exception) {
            return $this->conflict($exception->getMessage());
        }
    }

    public function void(Request $request, CommercialDocument $commercialDocument): JsonResponse
    {
        $validator = Validator::make($request->all(), ['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        if ($validator->fails()) return $this->invalid($validator->errors()->toArray());
        try {
            return response()->json(['success' => true, 'data' => $this->present($this->documents->staffTransition($request->user(), $commercialDocument, 'void', $validator->validated()['reason'], $request))]);
        } catch (DomainException $exception) {
            return $this->conflict($exception->getMessage());
        }
    }

    public function history(CommercialDocument $commercialDocument): JsonResponse
    {
        $history = $commercialDocument->histories()->with(['user:id,name', 'customer:id,name'])->get()->map(fn ($event) => [
            'action' => $event->action,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'metadata' => $event->metadata,
            'actor' => $event->user ? ['type' => 'staff', 'name' => $event->user->name] : ($event->customer ? ['type' => 'customer', 'name' => $event->customer->name] : ['type' => 'system', 'name' => null]),
            'created_at' => $event->created_at,
        ]);
        return response()->json(['success' => true, 'data' => $history]);
    }

    private function rules(bool $creating): array
    {
        return [
            'document_type' => [$creating ? 'required' : 'prohibited', 'in:quotation,invoice'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'organisation_id' => ['nullable', 'integer', 'exists:organisations,id'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,2'],
            'discount_description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.menu_item_id' => ['nullable', 'integer', 'exists:menu_items,id'],
            'lines.*.description' => ['nullable', 'required_without:lines.*.menu_item_id', 'string', 'max:255'],
            'lines.*.detail' => ['nullable', 'string', 'max:2000'],
            'lines.*.unit_of_measure' => ['nullable', 'string', 'max:40'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000', 'decimal:0,3'],
            'lines.*.unit_price' => ['nullable', 'required_without:lines.*.menu_item_id', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,2'],
            'lines.*.tax_rate' => ['prohibited'],
            'subtotal' => ['prohibited'], 'tax_amount' => ['prohibited'], 'total' => ['prohibited'],
            'currency' => ['prohibited'], 'status' => ['prohibited'], 'document_number' => ['prohibited'],
            'issue_date' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
        ];
    }

    private function present(CommercialDocument $document): array
    {
        return $this->presenter->present($document);
    }

    private function invalid(array $errors): JsonResponse { return response()->json(['success' => false, 'message' => 'The given data was invalid.', 'errors' => $errors], 422); }
    private function conflict(string $message): JsonResponse { return response()->json(['success' => false, 'message' => $message], 409); }
}
