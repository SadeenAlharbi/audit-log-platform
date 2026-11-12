<?php

use App\Jobs\StoreAuditLogs;
use App\Models\AuditLog;
use App\Models\IngestionBatch;
use App\Models\IngestionKey;
use App\Models\SecurityEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->issued = issueKey();
});

it('accepts a log with 202 and stores it through the queue', function () {
    $payload = logPayload(['external_id' => 'order-1001-paid']);

    $uid = sendApi($this, $this->issued, 'POST', '/api/v1/logs', $payload)
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'accepted')
        ->json('data.uid');

    $log = AuditLog::query()->withoutGlobalScopes()->where('uid', $uid)->firstOrFail();

    expect($log)
        ->project_id->toBe($this->issued->key->project_id)
        ->api_key_id->toBe($this->issued->key->id)
        ->external_id->toBe('order-1001-paid')
        ->event->toBe('order.paid')
        ->actor_name->toBe('Sara')
        ->actor_ip->toBe('10.0.0.5')
        ->subject_id->toBe('1001')
        ->old_values->toBe(['status' => 'pending'])
        ->tags->toBe(['billing'])
        ->correlation_id->toBe('corr-1');

    // D8: credentials are redacted before storage; they exist nowhere.
    expect($log->new_values['password'])->toBe('[REDACTED]')
        ->and($log->new_values['status'])->toBe('paid');
});

it('redacts never-store keys at any depth but keeps personal data for authorised viewers', function () {
    $uid = sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload([
        'new_values' => [
            'card' => ['cardNumber' => '4111111111111111', 'cvv' => '123'],
            'accessToken' => 'tok',
            'national_id' => '1234567890',
            'shipping_address' => 'Riyadh',
        ],
        'metadata' => ['headers' => ['Authorization' => 'Bearer x']],
    ]))->assertStatus(202)->json('data.uid');

    $log = AuditLog::query()->withoutGlobalScopes()->where('uid', $uid)->firstOrFail();

    expect($log->new_values)->toBe([
        'card' => ['cardNumber' => '[REDACTED]', 'cvv' => '[REDACTED]'],
        'accessToken' => '[REDACTED]',
        'national_id' => '1234567890',
        'shipping_address' => 'Riyadh',
    ])->and($log->metadata['headers']['Authorization'])->toBe('[REDACTED]');
});

it('answers before storing: the log is only queued', function () {
    Queue::fake();

    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload())->assertStatus(202);

    Queue::assertPushedOn('ingest', StoreAuditLogs::class);
    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('returns a uniform validation error', function () {
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', ['event' => ''])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['event', 'action', 'occurred_at']]]);
});

it('rejects unknown fields', function () {
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload(['hacker' => 1, 'actor' => ['id' => '1', 'role' => 'x']]))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['hacker', 'actor.role']]]);
});

it('rejects occurred_at outside the accepted window', function (string $when) {
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload(['occurred_at' => $when]))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['occurred_at']]]);
})->with([
    'too old' => fn () => CarbonImmutable::now()->subDays(400)->toIso8601String(),
    'in the future' => fn () => CarbonImmutable::now()->addHour()->toIso8601String(),
]);

it('detects a duplicate external_id before answering (409)', function () {
    $payload = logPayload(['external_id' => 'dup-1']);
    $first = sendApi($this, $this->issued, 'POST', '/api/v1/logs', $payload)->assertStatus(202)->json('data.uid');

    // Same external_id, different occurred_at: still a duplicate (D2).
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload(['external_id' => 'dup-1', 'occurred_at' => now()->subMinutes(30)->toIso8601String()]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'duplicate')
        ->assertJsonPath('error.details.uid', $first);

    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('lets another project reuse the same external_id', function () {
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload(['external_id' => 'shared']))->assertStatus(202);
    sendApi($this, issueKey(), 'POST', '/api/v1/logs', logPayload(['external_id' => 'shared']))->assertStatus(202);

    expect(IngestionKey::query()->count())->toBe(2);
});

it('accepts logs without external_id (no idempotency)', function () {
    $payload = logPayload();
    unset($payload['external_id']);

    sendApi($this, $this->issued, 'POST', '/api/v1/logs', $payload)->assertStatus(202);
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', $payload)->assertStatus(202);

    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('rejects payloads over 1MB', function () {
    config(['audit.api.max_payload_bytes' => 1024]);

    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload(['metadata' => ['blob' => str_repeat('a', 2000)]]))
        ->assertStatus(413)
        ->assertJsonPath('error.code', 'payload_too_large');

    expect(SecurityEvent::query()->where('reason', 'payload_too_large')->exists())->toBeTrue();
});

it('rejects a body that is not JSON', function () {
    $headers = apiHeaders($this->issued);
    $headers['Content-Type'] = 'text/plain';

    $this->call('POST', '/api/v1/logs', [], [], [], transformHeadersToServerVars($headers), 'hello')->assertStatus(415);
});

it('accepts a batch with valid, invalid and duplicate items', function () {
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload(['external_id' => 'already']))->assertStatus(202);

    $response = sendApi($this, $this->issued, 'POST', '/api/v1/logs/batch', ['logs' => [
        logPayload(['external_id' => 'b-1']),
        logPayload(['event' => '']),
        logPayload(['external_id' => 'already']),
        logPayload(['external_id' => 'b-1']), // duplicate inside the batch
        logPayload(['external_id' => 'b-2']),
    ]])->assertStatus(202);

    $response->assertJsonPath('data.total', 5)
        ->assertJsonPath('data.accepted', 2)
        ->assertJsonPath('data.duplicates', 2)
        ->assertJsonPath('data.rejected', 1)
        ->assertJsonPath('data.items.0.status', 'accepted')
        ->assertJsonPath('data.items.1.status', 'rejected')
        ->assertJsonPath('data.items.2.status', 'duplicate')
        ->assertJsonPath('data.items.3.status', 'duplicate')
        ->assertJsonPath('data.items.3.uid', $response->json('data.items.0.uid'));

    // Every id in the API is a canonical (upper-case) ULID, as in the OpenAPI.
    expect($response->json('data.batch_uid'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($response->json('data.items.0.uid'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');

    $batch = IngestionBatch::query()->where('uid', $response->json('data.batch_uid'))->firstOrFail();
    expect($batch)->status->toBe('partially_failed')->accepted_count->toBe(2)->rejected_count->toBe(1);
    expect(AuditLog::query()->withoutGlobalScopes()->where('batch_id', $batch->id)->count())->toBe(2);

    sendApi($this, $this->issued, 'GET', '/api/v1/batches/'.$batch->uid)
        ->assertOk()
        ->assertJsonPath('data.status', 'partially_failed');
});

it('rejects a batch where nothing is valid', function () {
    sendApi($this, $this->issued, 'POST', '/api/v1/logs/batch', ['logs' => [['event' => ''], ['x' => 1]]])
        ->assertStatus(422)
        ->assertJsonCount(2, 'error.details.items');
});

it('limits a batch to 500 logs', function () {
    sendApi($this, $this->issued, 'POST', '/api/v1/logs/batch', ['logs' => array_fill(0, 501, ['event' => 'x'])])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['logs']]]);
});

it('stores 500 logs from one batch', function () {
    $logs = array_map(fn ($i) => logPayload(['external_id' => "bulk-{$i}"]), range(1, 500));

    sendApi($this, $this->issued, 'POST', '/api/v1/logs/batch', ['logs' => $logs])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', 500);

    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(500);
});

it('stores a job retry only once', function () {
    Queue::fake();
    sendApi($this, $this->issued, 'POST', '/api/v1/logs', logPayload())->assertStatus(202);

    $job = Queue::pushed(StoreAuditLogs::class)->first();
    $job->handle();
    $job->handle();

    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(1);
});
