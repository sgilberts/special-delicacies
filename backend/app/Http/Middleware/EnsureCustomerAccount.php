<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account instanceof Customer) {
            return response()->json(['success' => false, 'message' => 'Customer account required.'], 403);
        }

        if (! $account->is_active) {
            return response()->json(['success' => false, 'message' => 'This customer account is deactivated.'], 403);
        }

        return $next($request);
    }
}
