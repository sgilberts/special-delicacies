<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full customer features (deposits, credit and billing) require both the
 * email address and the telephone number to be verified. The check reads the
 * account on every request, so completing the second verification unlocks the
 * features immediately without recreating the account.
 */
class EnsureCustomerFullyVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account instanceof Customer) {
            return response()->json(['success' => false, 'message' => 'Customer account required.'], 403);
        }

        $missing = [];
        if (! $account->email || ! $account->email_verified_at) {
            $missing[] = 'email';
        }
        if (! $account->phone_verified_at) {
            $missing[] = 'phone';
        }

        if ($missing !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Verify both your email address and telephone number to unlock deposits, credit and billing features.',
                'code' => 'customer_verification_required',
                'missing' => $missing,
            ], 403);
        }

        return $next($request);
    }
}
