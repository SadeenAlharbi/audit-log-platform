<?php

use App\Http\Middleware\Api\AuthenticateApiKey;
use App\Http\Middleware\Api\ForceJson;
use App\Http\Middleware\Api\LimitPayloadSize;
use App\Http\Middleware\Api\RequireHttps;
use App\Http\Middleware\Api\RequireScope;
use App\Http\Middleware\Api\ThrottleByIp;
use App\Http\Middleware\Api\VerifySignature;
use App\Http\Responses\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a TLS-terminating load balancer set TRUSTED_PROXIES
        // (comma-separated, or "*") so HTTPS and client IPs are detected.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') === '*' ? '*' : array_filter(explode(',', (string) env('TRUSTED_PROXIES', ''))));

        // Security review M2: only the APP_URL host is accepted, so a forged
        // Host header cannot poison password-reset links (enforced outside
        // local / tests by Laravel's TrustHosts).
        $middleware->trustHosts(at: fn () => ['^'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'$'], subdomains: false);

        $middleware->alias([
            'api.json' => ForceJson::class,
            'api.https' => RequireHttps::class,
            'api.payload' => LimitPayloadSize::class,
            'api.key' => AuthenticateApiKey::class,
            'api.signature' => VerifySignature::class,
            'api.scope' => RequireScope::class,
            'api.throttle-ip' => ThrottleByIp::class,
        ]);

        // The API authenticates the key before throttling per key; the
        // default priority list would otherwise run throttle first.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateApiKey::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: VerifySignature::class);
        $middleware->prependToPriorityList(before: AuthenticateApiKey::class, prepend: LimitPayloadSize::class);
        $middleware->prependToPriorityList(before: LimitPayloadSize::class, prepend: RequireHttps::class);
        $middleware->prependToPriorityList(before: RequireHttps::class, prepend: ThrottleByIp::class);
        $middleware->appendToPriorityList(after: ThrottleRequests::class, append: RequireScope::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::fromException($e);
            }

            return null;
        });
    })->create();
