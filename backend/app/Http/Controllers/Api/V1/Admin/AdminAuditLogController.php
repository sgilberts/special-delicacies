<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    /**
     * Display paginated administrative audit logs.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AdminAuditLog::with('user:id,name,email');

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('actor_id')) {
            $query->where('user_id', $request->input('actor_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('action', 'like', "%{$search}%")
                  ->orWhere('entity_id', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('from_date')) {
            $from = Carbon::parse($request->input('from_date'))->startOfDay();
            $query->where('created_at', '>=', $from);
        }

        if ($request->filled('to_date')) {
            $to = Carbon::parse($request->input('to_date'))->endOfDay();
            $query->where('created_at', '<=', $to);
        }

        $logs = $query->latest('created_at')->paginate($request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data'    => $logs->items(),
            'meta'    => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }
}
