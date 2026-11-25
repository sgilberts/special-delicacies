<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestaurantSettingController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Display restaurant settings, optionally filtered by group or organized by groups.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RestaurantSetting::query();

        if ($request->has('group')) {
            $query->where('group', $request->input('group'));
        }

        $settings = $query->get();

        // Also build a structured dictionary grouped by group
        $grouped = [];
        foreach ($settings as $setting) {
            $grouped[$setting->group][$setting->key] = $setting->value;
        }

        return response()->json([
            'success' => true,
            'data'    => $settings,
            'grouped' => $grouped,
        ]);
    }

    /**
     * Update restaurant settings.
     */
    public function update(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $validated = $request->validate([
            'settings'          => ['required', 'array'],
            'settings.*.key'    => ['required', 'string'],
            'settings.*.value'  => ['nullable', 'string'],
            'settings.*.group'  => ['nullable', 'string'],
        ]);

        $oldValues = RestaurantSetting::all()->pluck('value', 'key')->all();

        DB::transaction(function () use ($validated, $currentUser, $oldValues, $request) {
            $newValues = [];

            foreach ($validated['settings'] as $item) {
                $key = $item['key'];
                $val = $item['value'] ?? null;
                $grp = $item['group'] ?? 'general';

                // Security constraint: Currency remains GHS and GH₵
                if ($key === 'currency') {
                    $val = 'GHS';
                }
                if ($key === 'currency_symbol') {
                    $val = 'GH₵';
                }

                RestaurantSetting::setValue($key, $val, $grp);
                $newValues[$key] = $val;
            }

            $this->auditService->log(
                $currentUser,
                'settings.update',
                'Updated restaurant operational and system settings.',
                null,
                $oldValues,
                $newValues,
                $request
            );
        });

        $refreshed = RestaurantSetting::all();
        $grouped = [];
        foreach ($refreshed as $setting) {
            $grouped[$setting->group][$setting->key] = $setting->value;
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully.',
            'data'    => $refreshed,
            'grouped' => $grouped,
        ]);
    }
}
