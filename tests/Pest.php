<?php

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyManager;
use App\Services\ApiKeys\IssuedApiKey;
use App\Support\Auth\RequestSignature;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
| Feature tests run inside a transaction (RefreshDatabase).
| Integration tests run without one (DatabaseMigrations) because they use a
| second DB connection (archiver) or DDL, which commits implicitly in MariaDB.
*/
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->use(DatabaseMigrations::class)->in('Integration');

/**
 * Insert one audit log the way the ingestion worker will.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeAuditLog(Project $project, array $overrides = []): AuditLog
{
    $now = CarbonImmutable::now('UTC');

    return AuditLog::query()->withoutGlobalScopes()->create(array_merge([
        'uid' => (string) Str::ulid(),
        'project_id' => $project->id,
        'event' => 'user.updated',
        'action' => 'update',
        'severity' => 'info',
        'actor_type' => 'user',
        'actor_id' => '42',
        'subject_type' => 'order',
        'subject_id' => '1001',
        'old_values' => ['status' => 'pending'],
        'new_values' => ['status' => 'paid'],
        'occurred_at' => $now,
        'received_at' => $now,
    ], $overrides));
}

/**
 * @param  list<string>  $scopes
 * @param  list<string>|null  $ips
 */
function issueKey(?Project $project = null, array $scopes = [ProjectApiKey::SCOPE_WRITE], bool $signed = false, ?array $ips = null): IssuedApiKey
{
    return app(ApiKeyManager::class)->create(
        project: $project ?? Project::factory()->create(),
        name: 'test key',
        scopes: $scopes,
        withSignature: $signed,
        allowedIps: $ips,
        allowCombinedScopes: true,
    );
}

/**
 * Headers for an API call. Signs the request when the key has a secret.
 *
 * @return array<string, string>
 */
function apiHeaders(IssuedApiKey $issued, string $method = 'POST', string $uri = '/api/v1/logs', string $body = '', ?int $timestamp = null): array
{
    $headers = [
        'Authorization' => 'Bearer '.$issued->plainKey,
        'X-Project' => $issued->key->project->slug,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ];

    if ($issued->plainSecret !== null) {
        $ts = (string) ($timestamp ?? time());
        $headers['X-Timestamp'] = $ts;
        $headers['X-Signature'] = RequestSignature::sign($issued->plainSecret, $ts, $method, $uri, $body);
    }

    return $headers;
}

/**
 * A valid log payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function logPayload(array $overrides = []): array
{
    return array_merge([
        'external_id' => 'evt-'.Str::random(10),
        'event' => 'order.paid',
        'action' => 'update',
        'severity' => 'info',
        'occurred_at' => CarbonImmutable::now('UTC')->subMinute()->toIso8601String(),
        'actor' => ['type' => 'user', 'id' => '42', 'name' => 'Sara', 'ip' => '10.0.0.5'],
        'subject' => ['type' => 'order', 'id' => '1001'],
        'old_values' => ['status' => 'pending'],
        'new_values' => ['status' => 'paid', 'password' => 'secret-value'],
        'metadata' => ['source' => 'erp'],
        'tags' => ['billing'],
        'request_id' => 'req-1',
        'correlation_id' => 'corr-1',
    ], $overrides);
}

/** Send a JSON request with the raw body exactly as signed. */
function sendApi(TestCase $test, IssuedApiKey $issued, string $method, string $uri, ?array $payload = null, ?int $timestamp = null): TestResponse
{
    $body = $payload === null ? '' : json_encode($payload);

    return $test->call($method, $uri, [], [], [], transformHeadersToServerVars(apiHeaders($issued, $method, $uri, $body, $timestamp)), $body);
}

/** @param array<string, string> $headers */
function transformHeadersToServerVars(array $headers): array
{
    $server = [];
    foreach ($headers as $name => $value) {
        $name = strtoupper(str_replace('-', '_', $name));
        $server[in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $name : 'HTTP_'.$name] = $value;
    }

    return $server;
}

function seedRoles(): void
{
    (new RolesAndPermissionsSeeder)->run();
}

/** A dashboard user with a platform role. */
function platformUser(string $role): User
{
    seedRoles();
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

/** A dashboard user with a role on one project. */
function projectUser(Project $project, string $role): User
{
    seedRoles();
    $user = User::factory()->create(['is_active' => true]);
    DB::table('project_user')->insert([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'role_id' => Role::findByName($role)->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}
