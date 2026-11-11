<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\SecurityEvent;
use App\Services\ApiKeys\ApiKeyManager;

beforeEach(function () {
    $this->issued = issueKey();
});

it('accepts a valid key and project', function () {
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload())
        ->assertStatus(202)
        ->assertJsonStructure(['data' => ['uid', 'status']]);
});

it('rejects requests without credentials and records a security event', function () {
    $this->postJson('/api/v1/logs', logPayload())
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthorized');

    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('missing_credentials');
});

it('rejects an unknown key', function () {
    $this->postJson('/api/v1/logs', logPayload(), [
        'Authorization' => 'Bearer alh_'.str_repeat('x', 44),
        'X-Project' => $this->issued->key->project->slug,
    ])->assertStatus(401);

    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('invalid_key');
});

it('rejects a key used with another project slug', function () {
    $other = Project::factory()->create();

    $this->postJson('/api/v1/logs', logPayload(), [
        'Authorization' => 'Bearer '.$this->issued->plainKey,
        'X-Project' => $other->slug,
    ])->assertStatus(401);

    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('project_mismatch');
});

it('rejects revoked and expired keys', function () {
    app(ApiKeyManager::class)->revoke($this->issued->key);
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload())->assertStatus(401);
    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('key_revoked');

    $expired = issueKey();
    $expired->key->forceFill(['expires_at' => now()->subMinute()])->save();
    sendApi($this, $expired, 'POST', '/api/v1/logs', logPayload())->assertStatus(401);
    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('key_expired');
});

it('rejects keys of an archived project', function () {
    $this->issued->key->project->archive();

    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload())
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('enforces the allowed IP list', function () {
    $restricted = issueKey(ips: ['203.0.113.0/24']);

    sendApi($this, $restricted, 'POST', '/api/v1/logs', logPayload())->assertStatus(403);
    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('ip_not_allowed');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
    sendApi($this, $restricted, 'POST', '/api/v1/logs', logPayload())->assertStatus(202);
});

it('enforces scopes: a write key cannot read and a read key cannot write', function () {
    sendApi($this, $this->issued, 'GET', '/api/v1/logs')->assertStatus(403);
    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('scope_denied');

    $reader = issueKey(scopes: [ProjectApiKey::SCOPE_READ]);
    sendApi($this, $reader, 'POST', '/api/v1/logs', logPayload())->assertStatus(403);
    sendApi($this, $reader, 'GET', '/api/v1/logs')->assertOk();
});

it('updates last_used_at of the key', function () {
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload())->assertStatus(202);

    expect($this->issued->key->fresh()->last_used_at)->not->toBeNull();
});

it('rejects plain HTTP when HTTPS is required', function () {
    config(['audit.api.require_https' => true]);

    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload())->assertStatus(403);
    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('https_required');
});

it('does not allow updating or deleting logs through the API', function () {
    foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
        $this->json($method, '/api/v1/logs/01JABCDEFGHJKMNPQRSTVWXYZ0')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    }
});

it('does not send CORS headers to browsers', function () {
    $this->call('OPTIONS', '/api/v1/logs', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
