<?php

namespace App\Services\Ingestion;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyManager;
use App\Services\Audit\PlatformAuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * "Send test log" from the dashboard (plan §5): sends a real, signed
 * POST /api/v1/logs through the same route, middleware (HTTPS, size limit,
 * key, signature, rate limit), validation, queue and job as an integrated
 * system, then waits briefly for the log to be stored.
 *
 * It uses a temporary signed write key that is revoked as soon as the request
 * is answered (keys are never deleted: the revoked key stays in the project's
 * key list as evidence of the test).
 */
class TestLogSender
{
    public function __construct(
        private ApiKeyManager $keys,
        private PlatformAuditLogger $audit,
        private Router $router,
    ) {}

    /**
     * @return array{uid: string|null, stored: bool, status: int}
     */
    public function send(Project $project, ?User $by = null, int $waitSeconds = 5): array
    {
        $issued = $this->keys->create(
            $project,
            'اختبار من لوحة التحكم (مؤقت)',
            [ProjectApiKey::SCOPE_WRITE],
            'production',
            true,
            null,
            $by,
            false,
            now()->addMinutes(5),
        );

        try {
            $response = $this->dispatch($project, $issued->plainKey, (string) $issued->plainSecret, $by);
        } finally {
            $this->keys->revoke($issued->key, $by, 'temporary key of the dashboard test button');
        }

        $status = $response['status'];
        $uid = $response['uid'];
        $this->audit->record('project.test_log_sent', $project, ['uid' => $uid, 'http_status' => $status], $by);

        if ($status !== 202 || $uid === null) {
            return ['uid' => $uid, 'stored' => false, 'status' => $status];
        }

        $deadline = microtime(true) + $waitSeconds;
        do {
            if (AuditLog::query()->withoutGlobalScopes()->where('uid', $uid)->exists()) {
                return ['uid' => $uid, 'stored' => true, 'status' => $status];
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        return ['uid' => $uid, 'stored' => false, 'status' => $status];
    }

    /**
     * @return array{status: int, uid: string|null}
     */
    private function dispatch(Project $project, string $plainKey, string $secret, ?User $by): array
    {
        $path = '/api/v1/logs';
        $body = (string) json_encode([
            'event' => 'platform.test',
            'action' => 'custom',
            'severity' => 'info',
            'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'actor' => array_filter(['type' => 'dashboard_user', 'id' => $by ? (string) $by->id : null, 'name' => $by?->name]),
            'subject' => ['type' => 'project', 'id' => (string) $project->id],
            'metadata' => ['source' => 'dashboard test button'],
            'tags' => ['test'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}\nPOST\n{$path}\n{$body}", $secret);

        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $request = Request::create("https://{$host}{$path}", 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'HTTP_X_PROJECT' => $project->slug,
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => app()->runningInConsole() ? '127.0.0.1' : (string) request()->ip(),
        ], content: $body);

        // Handle it as the current request (form requests, the rate limiter
        // and the key resolver read it from the container), then put the
        // dashboard's own request back.
        $original = app('request');
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
        // An external system has no dashboard session: without this, the
        // dashboard user's project scopes would apply to the API lookups.
        $guard = auth()->guard();
        $dashboardUser = $guard->user();
        $guard->forgetUser();
        // The full middleware stack is the point of this test, even where it
        // was switched off for the outer request (Livewire's test harness).
        $middlewareDisabled = app()->bound('middleware.disable') ? app('middleware.disable') === true : null;
        app()->instance('middleware.disable', false);
        try {
            $response = $this->router->dispatch($request);
        } catch (Throwable) {
            return ['status' => 500, 'uid' => null];
        } finally {
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
            if ($dashboardUser !== null) {
                $guard->setUser($dashboardUser);
            }
            if ($middlewareDisabled === null) {
                app()->offsetUnset('middleware.disable');
            } else {
                app()->instance('middleware.disable', $middlewareDisabled);
            }
        }

        $data = json_decode((string) $response->getContent(), true);

        return ['status' => $response->getStatusCode(), 'uid' => is_array($data) ? ($data['data']['uid'] ?? null) : null];
    }
}
