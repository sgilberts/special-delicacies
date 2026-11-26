<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommercialDocument;
use App\Models\PaymentAllocation;
use App\Models\PaymentAllocationOperation;
use App\Models\PaymentTransaction;
use App\Services\PaymentAllocationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentAllocationController extends Controller
{
    public function __construct(private readonly PaymentAllocationService $allocations) {}

    public function payments(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->allocations->eligiblePayments($validated);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->pageMeta($page)]);
    }

    public function payment(Request $request, int $paymentId): JsonResponse
    {
        $payment = PaymentTransaction::query()->with(['customer:id,name,email', 'organisation:id,name,reference'])->findOrFail($paymentId);
        abort_unless($payment->payment_purpose === 'invoice' && $payment->order_id === null, 404);
        $operations = PaymentAllocationOperation::query()->with(['allocations.commercialDocument', 'allocations.reversal.actor:id,name', 'actor:id,name'])
            ->where('payment_transaction_id', $payment->id)->orderByDesc('allocated_at')->paginate(50);
        return response()->json(['success' => true, 'data' => [
            'id' => $payment->id,
            'transaction_reference' => $payment->transaction_reference,
            'provider_reference' => $payment->provider_reference,
            'provider' => $payment->provider,
            'payment_method' => $payment->payment_method,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'payment_allocated_amount' => PaymentAllocationService::formatMinorUnits($this->allocations->paymentAllocatedMinorForPayment($payment->id)),
            'credit_designated_amount' => PaymentAllocationService::formatMinorUnits($this->allocations->creditDesignatedMinorForPayment($payment->id)),
            'unallocated_amount' => PaymentAllocationService::formatMinorUnits($this->allocations->availableMinorForPayment($payment->id)),
            'status' => $payment->status,
            'verified_at' => $payment->verified_at,
            'received_at' => $payment->received_at,
            'payer' => $payment->organisation
                ? ['type' => 'organisation', 'id' => $payment->organisation->id, 'name' => $payment->organisation->name]
                : ($payment->customer ? ['type' => 'customer', 'id' => $payment->customer->id, 'name' => $payment->customer->name] : null),
            'allocations' => $operations->getCollection()->map(fn ($operation) => [
                'operation_reference' => $operation->operation_reference,
                'allocated_at' => $operation->allocated_at,
                'allocated_by' => $operation->actor_name_snapshot ?: $operation->actor?->name,
                'allocations' => $operation->allocations->map(fn (PaymentAllocation $allocation) => [
                    'id' => $allocation->id,
                    'document_id' => $allocation->commercial_document_id,
                    'document_number' => $allocation->commercialDocument?->document_number,
                    'amount' => $allocation->amount,
                    'currency' => $allocation->currency,
                    'reversal' => $allocation->reversal ? ['reason' => $allocation->reversal->reason, 'operation_reference' => $allocation->reversal->operation_reference, 'reversed_by' => $allocation->reversal->actor_name_snapshot ?: $allocation->reversal->actor?->name, 'reversed_at' => $allocation->reversal->reversed_at] : null,
                ])->values(),
            ])->values(),
        ], 'meta' => $this->pageMeta($operations)]);
    }

    public function invoices(Request $request, int $paymentId): JsonResponse
    {
        abort_unless(PaymentTransaction::query()->whereKey($paymentId)->where('payment_purpose', 'invoice')->whereNull('order_id')->exists(), 404);
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->allocations->eligibleInvoices($paymentId);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->pageMeta($page)]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_transaction_id' => ['required', 'integer', 'min:1'],
            'allocations' => ['required', 'array', 'min:1', 'max:50'],
            'allocations.*.commercial_document_id' => ['required', 'integer', 'min:1', 'distinct'],
            'allocations.*.amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);
        try {
            return response()->json(['success' => true, 'data' => $this->allocations->preview(
                (int) $validated['payment_transaction_id'], $validated['allocations'],
            )]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    public function invoiceBalance(CommercialDocument $commercialDocument): JsonResponse
    {
        abort_unless($commercialDocument->document_type === CommercialDocument::TYPE_INVOICE, 404);
        return response()->json(['success' => true, 'data' => $this->allocations->summaryForDocument($commercialDocument)]);
    }

    public function invoiceAllocations(CommercialDocument $commercialDocument): JsonResponse
    {
        abort_unless($commercialDocument->document_type === CommercialDocument::TYPE_INVOICE, 404);
        $page = $this->allocations->allocationsFor($commercialDocument);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->pageMeta($page)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_transaction_id' => ['required', 'integer', 'min:1'],
            'operation_reference' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['required', 'array', 'min:1', 'max:50'],
            'allocations.*.commercial_document_id' => ['required', 'integer', 'min:1', 'distinct'],
            'allocations.*.amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);
        try {
            $result = $this->allocations->allocate(
                (int) $validated['payment_transaction_id'],
                $validated['allocations'],
                $validated['operation_reference'],
                $request->user(),
                $validated['reason'] ?? null,
            );
            foreach ($result['allocations'] as &$line) {
                $document = CommercialDocument::query()->find($line['commercial_document_id']);
                $line['balance'] = $document ? $this->allocations->balance($document) : null;
            }
            unset($line);
            return response()->json(['success' => true, 'data' => $result], 201);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    public function history(): JsonResponse
    {
        $page = PaymentAllocationOperation::query()->with(['paymentTransaction:id,transaction_reference,amount,currency,provider', 'actor:id,name', 'allocations.commercialDocument', 'allocations.reversal'])
            ->latest('allocated_at')->paginate(50);
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => $this->pageMeta($page)]);
    }

    public function reverse(Request $request, PaymentAllocation $paymentAllocation): JsonResponse
    {
        $validated = $request->validate([
            'operation_reference' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        try {
            return response()->json(['success' => true, 'data' => $this->allocations->reverse(
                $paymentAllocation,
                $validated['operation_reference'],
                $validated['reason'],
                $request->user(),
            )]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }
    }

    private function pageMeta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
