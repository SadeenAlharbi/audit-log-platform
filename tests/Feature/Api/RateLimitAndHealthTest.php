<?php

use App\Models\SecurityEvent;

it('limits requests per key and records rate_limited', function () {
    config(['audit.api.rate_limit_per_minute' => 2]);
    $issued = issueKey();

    sendApi($this, $issued, 'POST', '/api/v1/logs', logPayload())->assertStatus(202);
    sendApi($this, $issued, 'POST', '/api/v1/logs', logPayload())->assertStatus(202);
    sendApi($this, $issued, 'POST', '/api/v1/logs', logPayload())
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');

    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('rate_limited');

    // Another key has its own budget.
    sendApi($this, issueKey(), 'POST', '/api/v1/logs', logPayload())->assertStatus(202);
});

it('reports readiness on /health without authentication', function () {
    config(['database.redis.default' => null]);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', 'ok')
        ->assertHeader('X-Request-Id');
});

it('echoes the client request id', function () {
    $this->getJson('/api/v1/health', ['X-Request-Id' => 'abc-123'])->assertHeader('X-Request-Id', 'abc-123');
});

it('serves the OpenAPI document', function () {
    $this->get('/api/v1/openapi.yaml')->assertOk()->assertSee('openapi: 3.1.0', false);
});
