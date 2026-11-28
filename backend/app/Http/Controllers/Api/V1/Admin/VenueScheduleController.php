<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Models\VenueClosure;
use App\Models\VenueOperatingHour;
use App\Models\VenueSpecialHour;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 5.2 venue scheduling configuration: weekly operating hours, special
 * hours and closures/maintenance periods. Configuration only — no availability
 * or slot calculation is performed here.
 */
class VenueScheduleController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function updateOperatingHours(Request $request, int $venueId): JsonResponse
    {
        $venue = Venue::find($venueId);
        if (! $venue) {
            return response()->json(['success' => false, 'message' => 'Venue not found.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'hours' => ['required', 'array', 'max:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.is_closed' => ['boolean'],
        ]);

        $oldHours = $venue->operatingHours()->get(['day_of_week', 'opens_at', 'closes_at', 'is_closed'])
            ->keyBy('day_of_week')
            ->toArray();

        DB::transaction(function () use ($venue, $validated): void {
            foreach ($validated['hours'] as $row) {
                VenueOperatingHour::updateOrCreate(
                    ['venue_id' => $venue->id, 'day_of_week' => $row['day_of_week']],
                    [
                        'opens_at' => $row['opens_at'] ?? null,
                        'closes_at' => $row['closes_at'] ?? null,
                        'is_closed' => (bool) ($row['is_closed'] ?? false),
                    ]
                );
            }
        });

        $this->auditService->log(
            $request->user(),
            'venue.operating_hours.updated',
            "Updated operating hours for venue {$venue->name}.",
            $venue,
            ['operating_hours' => $oldHours],
            ['operating_hours' => $venue->operatingHours()->get(['day_of_week', 'opens_at', 'closes_at', 'is_closed'])->keyBy('day_of_week')->toArray()],
            $request
        );

        return response()->json([
            'success' => true,
            'data' => $venue->operatingHours()->get(),
            'message' => 'Operating hours updated successfully.',
        ]);
    }

    public function storeSpecialHour(Request $request, int $venueId): JsonResponse
    {
        $venue = Venue::find($venueId);
        if (! $venue) {
            return response()->json(['success' => false, 'message' => 'Venue not found.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'date' => [
                'required',
                'date',
                Rule::unique('venue_special_hours', 'date')->where(fn ($query) => $query->where('venue_id', $venue->id)),
            ],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'is_closed' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:160'],
        ]);

        $specialHour = VenueSpecialHour::create($validated + ['venue_id' => $venue->id]);

        $this->auditService->log(
            $request->user(),
            'venue.special_hour.created',
            "Added special hours for venue {$venue->name} on {$specialHour->date->toDateString()}.",
            $specialHour,
            null,
            $specialHour->only(['date', 'opens_at', 'closes_at', 'is_closed', 'reason']),
            $request
        );

        return response()->json([
            'success' => true,
            'data' => $specialHour,
            'message' => 'Special hours saved successfully.',
        ], Response::HTTP_CREATED);
    }

    public function destroySpecialHour(Request $request, int $venueId, int $specialHourId): JsonResponse
    {
        $venue = Venue::find($venueId);
        $specialHour = $venue?->specialHours()->whereKey($specialHourId)->first();

        if (! $venue || ! $specialHour) {
            return response()->json(['success' => false, 'message' => 'Special hours not found.'], Response::HTTP_NOT_FOUND);
        }

        $snapshot = $specialHour->only(['date', 'opens_at', 'closes_at', 'is_closed', 'reason']);
        $specialHour->delete();

        $this->auditService->log(
            $request->user(),
            'venue.special_hour.deleted',
            "Removed special hours for venue {$venue->name}.",
            null,
            $snapshot,
            null,
            $request
        );

        return response()->json(['success' => true, 'message' => 'Special hours removed successfully.']);
    }

    public function storeClosure(Request $request, int $venueId): JsonResponse
    {
        $venue = Venue::find($venueId);
        if (! $venue) {
            return response()->json(['success' => false, 'message' => 'Venue not found.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'closure_type' => ['required', Rule::in(VenueClosure::TYPES)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $closure = VenueClosure::create($validated + [
            'venue_id' => $venue->id,
            'created_by' => $request->user()?->id,
        ]);

        $this->auditService->log(
            $request->user(),
            'venue.closure.created',
            "Scheduled {$closure->closure_type} for venue {$venue->name}.",
            $closure,
            null,
            $closure->only(['closure_type', 'starts_at', 'ends_at', 'reason']),
            $request
        );

        return response()->json([
            'success' => true,
            'data' => $closure,
            'message' => 'Closure saved successfully.',
        ], Response::HTTP_CREATED);
    }

    public function destroyClosure(Request $request, int $venueId, int $closureId): JsonResponse
    {
        $venue = Venue::find($venueId);
        $closure = $venue?->closures()->whereKey($closureId)->first();

        if (! $venue || ! $closure) {
            return response()->json(['success' => false, 'message' => 'Closure not found.'], Response::HTTP_NOT_FOUND);
        }

        $snapshot = $closure->only(['closure_type', 'starts_at', 'ends_at', 'reason']);
        $closure->delete();

        $this->auditService->log(
            $request->user(),
            'venue.closure.deleted',
            "Removed closure for venue {$venue->name}.",
            null,
            $snapshot,
            null,
            $request
        );

        return response()->json(['success' => true, 'message' => 'Closure removed successfully.']);
    }
}
