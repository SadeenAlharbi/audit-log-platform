<?php

namespace App\Http\Middleware\Api;

use App\Http\Responses\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan §7: payloads above 1MB are rejected.
 */
class LimitPayloadSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('audit.api.max_payload_bytes', 1048576);
        $declared = (int) $request->header('Content-Length', '0');

        if ($declared > $max || strlen((string) $request->getContent()) > $max) {
            return ApiError::make('payload_too_large', "The request body must not exceed {$max} bytes.", 413);
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true) && ! $request->isJson()) {
            return ApiError::make('unsupported_media_type', 'Content-Type must be application/json.', 415);
        }

        return $next($request);
    }
}
