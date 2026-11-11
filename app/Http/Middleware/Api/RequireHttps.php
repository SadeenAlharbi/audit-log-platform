<?php

namespace App\Http\Middleware\Api;

use App\Http\Responses\ApiError;
use App\Services\Security\SecurityEventRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan §7: HTTPS is mandatory. Behind a TLS-terminating proxy configure
 * TRUSTED_PROXIES so X-Forwarded-Proto is honoured.
 */
class RequireHttps
{
    public function __construct(private SecurityEventRecorder $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('audit.api.require_https') && ! $request->secure()) {
            $this->security->record($request, 'https_required');

            return ApiError::make('forbidden', 'HTTPS is required.', 403);
        }

        return $next($request);
    }
}
