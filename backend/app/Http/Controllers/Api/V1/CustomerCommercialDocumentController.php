<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommercialDocument;
use App\Models\Customer;
use App\Services\CommercialDocumentAccess;
use App\Services\CommercialDocumentPresenter;
use App\Services\CommercialDocumentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerCommercialDocumentController extends Controller
{
    public function __construct(private readonly CommercialDocumentAccess $access, private readonly CommercialDocumentService $documents, private readonly CommercialDocumentPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $type = (string) $request->route('type');
        abort_unless(in_array($type, [CommercialDocument::TYPE_QUOTATION, CommercialDocument::TYPE_INVOICE], true), 404);
        /** @var Customer $customer */
        $customer = $request->user();
        $query = $this->access->scopeForCustomer(CommercialDocument::query()->with(['lines', 'customer:id,name,email,phone', 'organisation:id,name,reference']), $customer)
            ->where('status', '!=', CommercialDocument::STATUS_DRAFT)
            ->where('document_type', $type);
        if ($request->filled('status')) $query->where('status', $request->query('status'));
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->query('from'));
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->query('to'));
        $documents = $query->latest('id')->paginate(max(1, min((int) $request->query('per_page', 20), 100)));
        foreach ($documents->getCollection() as $document) $this->documents->expireIfNeeded($document, $request);
        return response()->json([
            'success' => true,
            'data' => $documents->getCollection()->map(fn (CommercialDocument $document) => $this->present($customer, $document->fresh(['lines', 'customer', 'organisation']))),
            'meta' => ['current_page' => $documents->currentPage(), 'last_page' => $documents->lastPage(), 'per_page' => $documents->perPage(), 'total' => $documents->total()],
        ]);
    }

    public function show(Request $request, CommercialDocument $commercialDocument): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        abort_unless($commercialDocument->document_type === $request->route('type'), 404);
        abort_unless($this->access->allows($customer, $commercialDocument), 404);
        abort_if($commercialDocument->status === CommercialDocument::STATUS_DRAFT, 404);
        $this->documents->expireIfNeeded($commercialDocument, $request);
        return response()->json(['success' => true, 'data' => $this->present($customer, $commercialDocument->fresh(['lines', 'customer', 'organisation']))]);
    }

    public function accept(Request $request, CommercialDocument $commercialDocument): JsonResponse { return $this->transition($request, $commercialDocument, 'accept'); }
    public function decline(Request $request, CommercialDocument $commercialDocument): JsonResponse { return $this->transition($request, $commercialDocument, 'decline'); }

    public function history(Request $request, CommercialDocument $commercialDocument): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        abort_unless($commercialDocument->document_type === $request->route('type'), 404);
        abort_unless($this->access->allows($customer, $commercialDocument), 404);
        abort_if($commercialDocument->status === CommercialDocument::STATUS_DRAFT, 404);
        $history = $commercialDocument->histories()->with(['user:id,name', 'customer:id,name'])->get()->map(fn ($event) => [
            'action' => $event->action, 'from_status' => $event->from_status, 'to_status' => $event->to_status,
            'metadata' => $event->metadata,
            'actor' => $event->user ? ['type' => 'staff', 'name' => $event->user->name] : ($event->customer ? ['type' => 'customer', 'name' => $event->customer->name] : ['type' => 'system', 'name' => null]),
            'created_at' => $event->created_at,
        ]);
        return response()->json(['success' => true, 'data' => $history]);
    }

    private function transition(Request $request, CommercialDocument $document, string $action): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        abort_unless($document->document_type === $request->route('type'), 404);
        if (! $this->access->allows($customer, $document, 'documents.accept')) abort(404);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        try {
            $updated = $this->documents->customerTransition($customer, $document, $action, $validated['reason'] ?? null, $request);
            if ($updated->status === CommercialDocument::STATUS_EXPIRED) {
                return response()->json(['success' => false, 'message' => 'This quotation has expired.'], 409);
            }
            return response()->json(['success' => true, 'data' => $this->present($customer, $updated)]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    private function present(Customer $customer, CommercialDocument $document): array
    {
        $includeFinancials = $document->organisation_id === null
            || $this->access->allows($customer, $document, 'financials.view');
        return $this->presenter->present($document, $includeFinancials);
    }
}
