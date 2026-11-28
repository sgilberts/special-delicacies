<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganisationRequest;
use App\Http\Requests\Admin\UpdateOrganisationRequest;
use App\Models\OrganisationMembership;
use App\Services\OrganisationFinancialOverviewService;
use App\Services\OrganisationManagementService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Administrative organisation management.
 *
 * Every endpoint is guarded by its own granular `organisations.*` permission in
 * routes/api.php, so a direct API call cannot bypass the portal's restrictions.
 * Financial figures have a separate endpoint behind
 * `organisations.view_financials` and are never part of the profile payload.
 */
class AdminOrganisationController extends Controller
{
    public function __construct(
        private readonly OrganisationManagementService $organisations,
        private readonly OrganisationFinancialOverviewService $financials,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:active,inactive,archived'],
            'membership_status' => ['nullable', 'in:active,pending,suspended,removed'],
            'sort' => ['nullable', 'in:name,created,members'],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->organisations->listing($validated);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => $this->organisations->statusCounts(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $organisation = $this->organisations->findOrFail($id);

        return response()->json(['success' => true, 'data' => [
            'organisation' => $this->organisations->detail($organisation),
            'activity' => $this->organisations->activity($organisation),
        ]]);
    }

    public function financials(int $id): JsonResponse
    {
        $organisation = $this->organisations->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->financials->overview($organisation)]);
    }

    public function members(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:active,pending,suspended,removed'],
            'role' => ['nullable', 'in:owner,administrator,manager,member'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $organisation = $this->organisations->findOrFail($id);
        $page = $this->organisations->members($organisation, $validated);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Active customers offered as the organisation's primary contact. */
    public function contacts(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:150']]);

        return response()->json([
            'success' => true,
            'data' => $this->organisations->contactCandidates((string) ($validated['search'] ?? '')),
        ]);
    }

    public function store(StoreOrganisationRequest $request): JsonResponse
    {
        $organisation = $this->organisations->create($request->validated(), $request->user(), $request);

        return response()->json([
            'success' => true,
            'message' => 'Organisation created successfully.',
            'data' => ['organisation' => $this->organisations->detail($organisation)],
        ], 201);
    }

    public function update(UpdateOrganisationRequest $request, int $id): JsonResponse
    {
        $organisation = $this->organisations->findOrFail($id);
        $organisation = $this->organisations->update($organisation, $request->validated(), $request->user(), $request);

        return response()->json([
            'success' => true,
            'message' => 'Organisation updated successfully.',
            'data' => ['organisation' => $this->organisations->detail($organisation)],
        ]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $organisation = $this->organisations->findOrFail($id);

        try {
            $organisation = $this->organisations->archive($organisation, $request->user(), $request);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organisation archived. Members, documents and audit history are preserved.',
            'data' => ['organisation' => $this->organisations->detail($organisation)],
        ]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $organisation = $this->organisations->findOrFail($id);

        try {
            $organisation = $this->organisations->restore($organisation, $request->user(), $request);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organisation restored to active status.',
            'data' => ['organisation' => $this->organisations->detail($organisation)],
        ]);
    }

    public function updateMemberStatus(Request $request, int $id, int $membership): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'in:active,suspended,removed']]);

        $organisation = $this->organisations->findOrFail($id);
        $record = OrganisationMembership::query()->findOrFail($membership);

        try {
            $record = $this->organisations->updateMemberStatus($organisation, $record, $validated['status'], $request->user(), $request);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Membership updated.',
            'data' => [
                'id' => $record->id,
                'role' => $record->role,
                'status' => $record->status,
                'joined_at' => $record->joined_at,
            ],
            'organisation' => $this->organisations->detail($organisation->refresh()),
        ]);
    }
}
