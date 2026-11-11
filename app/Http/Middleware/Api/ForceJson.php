<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * API responses are always JSON, and every response carries a request id
 * (the client's X-Request-Id when sent, otherwise a generated one).
 */
class ForceJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $requestId = $request->header('X-Request-Id');
        if (! is_string($requestId) || ! preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $requestId)) {
            $requestId = (string) Str::ulid();
            $request->headers->set('X-Request-Id', $requestId);
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
