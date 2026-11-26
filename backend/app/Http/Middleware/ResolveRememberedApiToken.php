<?php

namespace App\Http\Middleware;

use App\Services\ApiTokenSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveRememberedApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');
        $isCustomerApi = str_starts_with($path, '/api/v1/customer/') || $path === '/api/v1/orders';
        $cookieName = $isCustomerApi ? ApiTokenSession::CUSTOMER_COOKIE : ApiTokenSession::STAFF_COOKIE;
        $token = $request->cookie($cookieName);

        if (! is_string($token) || $token === '') {
            return $next($request);
        }

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $origin = $request->headers->get('Origin');
            $allowedOrigins = config('cors.allowed_origins', []);
            if (! $origin || ! in_array($origin, $allowedOrigins, true)) {
                return response()->json(['success' => false, 'message' => 'The request origin is not allowed.'], 403);
            }
        }

        $request->headers->set('Authorization', 'Bearer '.$token);
        $response = $next($request);
        if ($response->getStatusCode() === 401) {
            $response->headers->clearCookie($cookieName, '/', null, $request->isSecure(), true, false, 'lax');
        }

        return $response;
    }
}
