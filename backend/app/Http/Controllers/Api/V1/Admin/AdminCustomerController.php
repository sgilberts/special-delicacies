<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerRequest;
use App\Services\CustomerFinancialOverviewService;
use App\Services\CustomerManagementService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administrative customer management.
 *
 * Every endpoint is guarded by its own granular `customers.*` permission in
 * routes/api.php, so a direct API call cannot bypass the portal's restrictions.
 * Financial figures have a separate endpoint behind `customers.view_financials`
 * and are never part of the profile payload.
 */
class AdminCustomerController extends Controller
{
    public function __construct(
        private readonly CustomerManagementService $customers,
        private readonly CustomerFinancialOverviewService $financials,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:active,inactive,archived'],
            'type' => ['nullable', 'in:individual,organisation'],
            'sort' => ['nullable', 'in:name,joined'],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->customers->listing($validated);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => $this->customers->statusCounts(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $customer = $this->customers->findOrFail($id);

        return response()->json(['success' => true, 'data' => [
            'customer' => $this->customers->detail($customer),
            'activity' => $this->customers->activity($customer),
        ]]);
    }

    public function financials(int $id): JsonResponse
    {
        $customer = $this->customers->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->financials->overview($customer)]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->create($request->validated(), $request->user(), $request);

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully.',
            'data' => $this->customers->detail($customer),
        ], 201);
    }

    public function update(UpdateCustomerRequest $request, int $id): JsonResponse
    {
        $customer = $this->customers->findOrFail($id);
        $customer = $this->customers->update($customer, $request->validated(), $request->user(), $request);

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully.',
            'data' => $this->customers->detail($customer),
        ]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $customer = $this->customers->findOrFail($id);

        try {
            $customer = $this->customers->archive($customer, $request->user(), $request);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Customer archived. Financial and audit history is preserved.',
            'data' => $this->customers->detail($customer),
        ]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $customer = $this->customers->findOrFail($id);

        try {
            $customer = $this->customers->restore($customer, $request->user(), $request);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Customer restored to active status.',
            'data' => $this->customers->detail($customer),
        ]);
    }
}
