<?php

use App\Http\Controllers\Api\V1\BatchController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LogIngestController;
use App\Http\Controllers\Api\V1\LogQueryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Audit Log API v1 (plan §6, §7) - contract: docs/openapi.yaml
|--------------------------------------------------------------------------
| No route updates or deletes logs: PUT / PATCH / DELETE answer 405.
*/

Route::prefix('v1')->middleware(['api.json'])->group(function () {
    Route::get('health', HealthController::class)
        ->middleware('throttle:api-health')
        ->name('api.v1.health');

    Route::get('openapi.yaml', fn () => response((string) file_get_contents(base_path('docs/openapi.yaml')), 200, ['Content-Type' => 'application/yaml; charset=UTF-8']))
        ->name('api.v1.openapi');

    Route::middleware(['api.throttle-ip', 'api.https', 'api.payload', 'api.key', 'api.signature', 'throttle:audit-api'])->group(function () {
        Route::post('logs', [LogIngestController::class, 'store'])
            ->middleware('api.scope:logs:write')
            ->name('api.v1.logs.store');

        Route::post('logs/batch', [LogIngestController::class, 'batch'])
            ->middleware('api.scope:logs:write')
            ->name('api.v1.logs.batch');

        Route::get('logs', [LogQueryController::class, 'index'])
            ->middleware('api.scope:logs:read')
            ->name('api.v1.logs.index');

        Route::get('logs/{uid}', [LogQueryController::class, 'show'])
            ->middleware('api.scope:logs:read')
            ->where('uid', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
            ->name('api.v1.logs.show');

        Route::get('batches/{uid}', [BatchController::class, 'show'])
            ->middleware('api.scope:logs:write')
            ->where('uid', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
            ->name('api.v1.batches.show');
    });
});
