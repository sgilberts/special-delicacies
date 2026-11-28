<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class VenueController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Venue::query()->withCount('tables');

        if ($search = $request->query('search')) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('operational_status')) {
            $query->where('operational_status', $request->query('operational_status'));
        }

        if ($request->has('reservation_enabled')) {
            $query->where('reservation_enabled', filter_var($request->query('reservation_enabled'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $venues = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $venues->items(),
            'meta' => [
                'current_page' => $venues->currentPage(),
                'last_page' => $venues->lastPage(),
                'per_page' => $venues->perPage(),
                'total' => $venues->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $venue = Venue::create($validated);

        $this->auditService->log(
            $request->user(),
            'venue.created',
            "Created venue {$venue->name} ({$venue->code}).",
            $venue,
            null,
            $venue->only(array_keys($validated)),
            $request
        );

        return response()->json([
            'success' => true,
            'data' => $venue,
            'message' => 'Venue created successfully.',
        ], Response::HTTP_CREATED);
    }

    public function show(int $id): JsonResponse
    {
        $venue = Venue::with(['operatingHours', 'specialHours', 'closures', 'tables:id,table_number,venue_id,capacity,is_active,operational_status,reservation_eligible'])
            ->find($id);

        if (! $venue) {
            return response()->json(['success' => false, 'message' => 'Venue not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['success' => true, 'data' => $venue]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $venue = Venue::find($id);
        if (! $venue) {
            return response()->json(['success' => false, 'message' => 'Venue not found.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate($this->rules($venue->id));
        $oldValues = $venue->only(array_keys($validated));
        $venue->update($validated);

        $this->auditService->log(
            $request->user(),
            'venue.updated',
            "Updated venue {$venue->name} ({$venue->code}).",
            $venue,
            $oldValues,
            $venue->only(array_keys($validated)),
            $request
        );

        return response()->json([
            'success' => true,
            'data' => $venue->refresh(),
            'message' => 'Venue updated successfully.',
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $venue = Venue::find($id);
        if (! $venue) {
            return response()->json(['success' => false, 'message' => 'Venue not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($venue->tables()->exists() || $venue->reservations()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This venue cannot be deleted while it has tables or reservations. Deactivate it instead.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $snapshot = $venue->only(['name', 'code', 'operational_status']);
        $venue->delete();

        $this->auditService->log(
            $request->user(),
            'venue.deleted',
            "Deleted venue {$snapshot['name']} ({$snapshot['code']}).",
            null,
            $snapshot,
            null,
            $request
        );

        return response()->json(['success' => true, 'message' => 'Venue deleted successfully.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('venues', 'code')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'venue_type' => ['required', Rule::in(Venue::TYPES)],
            'location' => ['nullable', 'string', 'max:160'],
            'max_capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'operational_status' => ['required', Rule::in(Venue::STATUSES)],
            'reservation_enabled' => ['boolean'],
            'exclusive_reservation_enabled' => ['boolean'],
            'default_reservation_duration_minutes' => ['integer', 'min:15', 'max:720'],
            'reservation_slot_minutes' => ['integer', 'min:5', 'max:240'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
