<?php

namespace App\Http\Middleware\Api;

use App\Http\Responses\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-IP limit that runs BEFORE API key authentication (security review M4),
 * so a flood of unauthenticated requests is cut off before any database
 * work. Deliberately not a ThrottleRequests subclass: the middleware
 * priority list moves ThrottleRequests (and its subclasses) after key
 * authentication, where the per-key limiter belongs.
 */
class ThrottleByIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api-ip:'.sha1((string) $request->ip());
        $max = (int) config('audit.security.ip_rate_limit_per_minute', 3000);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = RateLimiter::availableIn($key);

            return ApiError::make('rate_limited', 'Too many requests from this address.', 429, null, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $max,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
