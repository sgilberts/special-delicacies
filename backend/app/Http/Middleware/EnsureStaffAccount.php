<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ($request->user() instanceof User)) {
            return response()->json(['success' => false, 'message' => 'Staff account required.'], 403);
        }

        return $next($request);
    }
}
