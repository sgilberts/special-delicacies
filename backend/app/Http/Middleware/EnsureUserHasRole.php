<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
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

        $parsedRoles = [];
        foreach ($roles as $role) {
            $split = preg_split('/[,|]/', $role);
            foreach ($split as $r) {
                $trimmed = trim($r);
                if ($trimmed !== '') {
                    $parsedRoles[] = $trimmed;
                }
            }
        }

        if (! $user->hasRole($parsedRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Insufficient role privileges.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
