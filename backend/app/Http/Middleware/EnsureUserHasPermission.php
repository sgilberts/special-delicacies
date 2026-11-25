<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is deactivated.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Parse any comma-separated or pipe-separated arguments passed by Laravel routing
        $parsedPermissions = [];
        foreach ($permissions as $permission) {
            $split = preg_split('/[,|]/', $permission);
            foreach ($split as $p) {
                $trimmed = trim($p);
                if ($trimmed !== '') {
                    $parsedPermissions[] = $trimmed;
                }
            }
        }

        if (! $user->hasAnyPermission($parsedPermissions)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. You do not have permission to perform this action.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
