<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\Organisation;
use App\Services\OrganisationAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganisationPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $customer = $request->user();
        if (! $customer instanceof Customer || ! $customer->is_active) {
            return response()->json(['success' => false, 'message' => 'Customer account required.'], 403);
        }

        $routeOrganisation = $request->route('organisation');
        $organisation = $routeOrganisation instanceof Organisation
            ? $routeOrganisation
            : Organisation::query()->find($routeOrganisation);

        // Do not disclose whether an inaccessible organisation exists.
        if (! $organisation) {
            return response()->json(['success' => false, 'message' => 'Organisation not found.'], 404);
        }

        $authorization = app(OrganisationAuthorization::class);
        if (! $authorization->membership($customer, $organisation)) {
            return response()->json(['success' => false, 'message' => 'Organisation not found.'], 404);
        }

        if (! $authorization->allows($customer, $organisation, $permission)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
