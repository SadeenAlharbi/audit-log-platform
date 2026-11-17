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

it('throttles unauthenticated floods per IP before checking keys', function () {
    config(['audit.security.ip_rate_limit_per_minute' => 3]);

    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/logs', [])->assertStatus(401);
    }

    $this->postJson('/api/v1/logs', [])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    expect(SecurityEvent::query()->where('reason', 'ip_rate_limited')->count())->toBe(1);
});

it('stores a bounded number of security events per IP and minute', function () {
    config(['audit.security.max_events_per_minute' => 2]);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/logs', [])->assertStatus(401);
    }

    expect(SecurityEvent::query()->count())->toBe(2);

    // The other 3 are counted, then written as one summary row (D21).
    $this->travel(1)->minutes();
    $this->artisan('audit:flush-security-events')->expectsOutputToContain('3 counted attempt(s) written')->assertSuccessful();

    $summary = SecurityEvent::query()->latest('id')->first();
    expect(SecurityEvent::query()->count())->toBe(3)
        ->and($summary->reason)->toBe('missing_credentials')
        ->and($summary->request_meta['suppressed'])->toBe(3);

    // Flushed once only.
    $this->artisan('audit:flush-security-events')->expectsOutputToContain('0 counted attempt(s) written');
});
