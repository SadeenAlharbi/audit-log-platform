<?php

use App\Models\ProjectApiKey;
use App\Models\SecurityEvent;

beforeEach(function () {
    $this->signed = issueKey(signed: true);
});

it('accepts a correctly signed request', function () {
    sendApi($this, $this->signed, 'POST', '/api/v1/logs', logPayload())->assertStatus(202);
});

it('requires the signature headers for signed keys', function () {
    $this->postJson('/api/v1/logs', logPayload(), [
        'Authorization' => 'Bearer '.$this->signed->plainKey,
        'X-Project' => $this->signed->key->project->slug,
    ])->assertStatus(401);

    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('missing_signature');
});

it('rejects a wrong signature', function () {
    $body = json_encode(logPayload());
    $headers = apiHeaders($this->signed, 'POST', '/api/v1/logs', $body);
    $headers['X-Signature'] = str_repeat('0', 64);

    $this->call('POST', '/api/v1/logs', [], [], [], transformHeadersToServerVars($headers), $body)->assertStatus(401);
    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('invalid_signature');
});

it('rejects a body changed after signing', function () {
    $headers = apiHeaders($this->signed, 'POST', '/api/v1/logs', json_encode(logPayload()));
    $tampered = json_encode(logPayload(['event' => 'tampered']));

    $this->call('POST', '/api/v1/logs', [], [], [], transformHeadersToServerVars($headers), $tampered)->assertStatus(401);
});

it('rejects timestamps older than 5 minutes (replay window)', function () {
    sendApi($this, $this->signed, 'POST', '/api/v1/logs', logPayload(), time() - 301)->assertStatus(401);
    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('stale_timestamp');
});

it('rejects the same signed request sent twice', function () {
    $body = json_encode(logPayload());
    $server = transformHeadersToServerVars(apiHeaders($this->signed, 'POST', '/api/v1/logs', $body));

    $this->call('POST', '/api/v1/logs', [], [], [], $server, $body)->assertStatus(202);
    $this->call('POST', '/api/v1/logs', [], [], [], $server, $body)->assertStatus(401);

    expect(SecurityEvent::query()->latest('id')->first()->reason)->toBe('replayed_request');
});

it('signs the query string of GET requests', function () {
    $reader = issueKey(scopes: [ProjectApiKey::SCOPE_READ], signed: true);

    sendApi($this, $reader, 'GET', '/api/v1/logs?severity=critical')->assertOk();

    // Signature computed for another query string is rejected.
    $headers = apiHeaders($reader, 'GET', '/api/v1/logs?severity=info');
    $this->call('GET', '/api/v1/logs?severity=critical', [], [], [], transformHeadersToServerVars($headers))->assertStatus(401);
});
