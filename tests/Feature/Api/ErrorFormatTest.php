<?php

use App\Http\Responses\ApiError;
use App\Models\ProjectApiKey;
use Illuminate\Support\Facades\Route;

/*
| Plan §6: every API error uses one JSON format with a stable code, and the
| OpenAPI document describes every route that exists.
*/

beforeEach(function () {
    $this->issued = issueKey(scopes: [ProjectApiKey::SCOPE_WRITE, ProjectApiKey::SCOPE_READ]);
});

it('answers unknown API routes with not_found in JSON', function () {
    $this->getJson('/api/v1/nope')
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Resource not found.']]);
});

it('answers a log that does not exist with not_found', function () {
    sendApi($this, $this->issued, 'GET', '/api/v1/logs/01JCDE0Z8Y2K7Q4M9V3N5T6W8X')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('refuses PUT, PATCH and DELETE with method_not_allowed', function (string $method) {
    $this->json($method, '/api/v1/logs')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');
})->with(['PUT', 'PATCH', 'DELETE']);

it('hides internal errors from clients when debug is off', function () {
    config(['app.debug' => false]);

    $response = ApiError::fromException(new RuntimeException('SQLSTATE secret detail'));

    expect($response->getStatusCode())->toBe(500)
        ->and(json_decode((string) $response->getContent(), true))
        ->toBe(['error' => ['code' => 'server_error', 'message' => 'Internal server error.']]);
});

it('maps HTTP exceptions to stable codes', function (int $status, string $code) {
    $response = ApiError::fromException(new Symfony\Component\HttpKernel\Exception\HttpException($status, 'x'));

    expect($response->getStatusCode())->toBe($status)
        ->and(json_decode((string) $response->getContent(), true)['error']['code'])->toBe($code);
})->with([
    [400, 'bad_request'],
    [409, 'conflict'],
    [415, 'unsupported_media_type'],
    [503, 'unavailable'],
    [502, 'server_error'],
]);

it('documents every API route in the OpenAPI file', function () {
    // Read "paths:" of the YAML without a YAML library: a path is a
    // 2-space indented key starting with "/", an operation a 4-space one.
    $documented = [];
    $path = null;
    $inPaths = false;
    foreach (file(base_path('docs/openapi.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^(\S[^:]*):/', $line, $m)) {
            $inPaths = $m[1] === 'paths';

            continue;
        }
        if (! $inPaths) {
            continue;
        }
        if (preg_match('#^  (/[^:]+):#', $line, $m)) {
            $path = preg_replace('/\{[^}]+\}/', '{}', $m[1]);
        } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete):/', $line, $m)) {
            $documented[] = strtoupper($m[1]).' /api/v1'.$path;
        }
    }

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && $r->getName() !== 'api.v1.openapi')
        ->flatMap(fn ($r) => collect($r->methods())
            ->reject(fn ($m) => $m === 'HEAD')
            ->map(fn ($m) => $m.' /'.preg_replace('/\{[^}]+\}/', '{}', $r->uri())))
        ->values()
        ->all();

    expect($routes)->not->toBeEmpty();
    foreach ($routes as $route) {
        expect($documented)->toContain($route);
    }
});
