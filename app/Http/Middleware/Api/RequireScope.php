<?php

namespace App\Http\Middleware\Api;

use App\Http\Responses\ApiError;
use App\Models\ProjectApiKey;
use App\Services\Security\SecurityEventRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('api.scope:logs:write')
 */
class RequireScope
{
    public function __construct(private SecurityEventRecorder $security) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        /** @var ProjectApiKey $key */
        $key = $request->attributes->get('api_key');

        if (! $key->hasScope($scope)) {
            $this->security->record($request, 'scope_denied', $key->project, $key, ['required_scope' => $scope]);

            return ApiError::make('forbidden', "This API key does not have the {$scope} scope.", 403);
        }

        return $next($request);
    }
}
