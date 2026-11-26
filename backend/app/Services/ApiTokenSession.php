<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class ApiTokenSession
{
    public const STAFF_COOKIE = 'sd_staff_remember';
    public const CUSTOMER_COOKIE = 'sd_customer_remember';

    /** @return array{token:string, minutes:int} */
    public function issue(Model $actor, string $name, bool $remember): array
    {
        $minutes = $remember ? 60 * 24 * 30 : 60 * 8;
        $expiresAt = now()->addMinutes($minutes);
        $token = $actor->createToken($name, ['*'], $expiresAt)->plainTextToken;

        return ['token' => $token, 'minutes' => $minutes];
    }

    public function attachRememberCookie($response, string $cookieName, string $token, int $minutes, Request $request)
    {
        return $response->withCookie(Cookie::create(
            $cookieName,
            $token,
            now()->addMinutes($minutes),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

    public function clearRememberCookie($response, string $cookieName, Request $request)
    {
        return $response->withCookie(Cookie::create(
            $cookieName,
            '',
            now()->subYear(),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }
}
