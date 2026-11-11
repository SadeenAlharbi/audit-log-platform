<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->reader = issueKey($this->project, [ProjectApiKey::SCOPE_READ]);
});

it('lists only the logs of the key\'s project, newest first', function () {
    makeAuditLog($this->project, ['event' => 'a', 'occurred_at' => now()->subMinutes(2)]);
    makeAuditLog($this->project, ['event' => 'b', 'occurred_at' => now()->subMinute()]);
    makeAuditLog(Project::factory()->create(), ['event' => 'other']);

    sendApi($this, $this->reader, 'GET', '/api/v1/logs')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.event', 'b')
        ->assertJsonPath('data.1.event', 'a')
        ->assertJsonMissingPath('data.0.id');
});

it('filters by event, actor, subject, severity, tag and time range', function () {
    makeAuditLog($this->project, ['event' => 'user.login', 'actor_id' => '7', 'severity' => 'warning', 'tags' => ['auth']]);
    makeAuditLog($this->project, ['event' => 'user.logout', 'actor_id' => '8']);
    makeAuditLog($this->project, ['event' => 'order.paid', 'subject_type' => 'order', 'subject_id' => '55', 'severity' => 'critical']);
    makeAuditLog($this->project, ['event' => 'old', 'occurred_at' => now()->subDays(60)]);

    $count = fn (string $query) => count(sendApi($this, $this->reader, 'GET', '/api/v1/logs?'.$query)->assertOk()->json('data'));

    expect($count('event=user.*'))->toBe(2)
        ->and($count('event=user.login'))->toBe(1)
        ->and($count('actor_id=8'))->toBe(1)
        ->and($count('subject_type=order&subject_id=55'))->toBe(1)
        ->and($count('severity=warning,critical'))->toBe(2)
        ->and($count('tag=auth'))->toBe(1)
        ->and($count(''))->toBe(3) // default range: last 30 days
        ->and($count('from='.now()->subDays(90)->toDateString()))->toBe(4);
});

it('paginates with a cursor', function () {
    foreach (range(1, 5) as $i) {
        makeAuditLog($this->project, ['event' => "e{$i}", 'occurred_at' => now()->subMinutes(10 - $i)]);
    }

    $first = sendApi($this, $this->reader, 'GET', '/api/v1/logs?per_page=2')->assertOk();
    $first->assertJsonCount(2, 'data')->assertJsonPath('data.0.event', 'e5');

    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    sendApi($this, $this->reader, 'GET', '/api/v1/logs?per_page=2&cursor='.$cursor)
        ->assertOk()
        ->assertJsonPath('data.0.event', 'e3');
});

it('masks sensitive values for API clients', function () {
    makeAuditLog($this->project, ['new_values' => ['password' => 'p@ss', 'name' => 'x', 'nested' => ['access_token' => 't']]]);

    sendApi($this, $this->reader, 'GET', '/api/v1/logs')
        ->assertJsonPath('data.0.new_values.password', '********')
        ->assertJsonPath('data.0.new_values.nested.access_token', '********')
        ->assertJsonPath('data.0.new_values.name', 'x');
});

it('shows one log by uid, and hides other projects\' logs', function () {
    $own = makeAuditLog($this->project);
    $foreign = makeAuditLog(Project::factory()->create());

    sendApi($this, $this->reader, 'GET', '/api/v1/logs/'.$own->uid)->assertOk()->assertJsonPath('data.uid', $own->uid);
    sendApi($this, $this->reader, 'GET', '/api/v1/logs/'.$foreign->uid)->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('validates query parameters', function () {
    sendApi($this, $this->reader, 'GET', '/api/v1/logs?per_page=1000')->assertStatus(422);
    sendApi($this, $this->reader, 'GET', '/api/v1/logs?unknown=1')->assertStatus(422);
    sendApi($this, $this->reader, 'GET', '/api/v1/logs?from=2020-01-01&to=2026-01-01')->assertStatus(422);
    sendApi($this, $this->reader, 'GET', '/api/v1/logs?severity=bad')->assertStatus(422);
});

it('returns times in UTC ISO-8601', function () {
    makeAuditLog($this->project, ['occurred_at' => CarbonImmutable::parse('2026-01-01 10:00:00.123', 'UTC')->setDate(now()->year, now()->month, now()->day)]);

    expect(sendApi($this, $this->reader, 'GET', '/api/v1/logs')->json('data.0.occurred_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T10:00:00\.123Z$/');
});
