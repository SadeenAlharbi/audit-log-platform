<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * GET /api/v1/health - readiness: database, Redis and ingest queue.
     * 200 when everything is reachable, 503 otherwise.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('SELECT 1')),
            'redis' => config('database.redis.default') ? $this->check(fn () => Redis::connection()->ping()) : 'skipped',
        ];

        $queue = null;
        try {
            $queue = Queue::size((string) config('audit.api.queue', 'ingest'));
        } catch (Throwable) {
            $checks['queue'] = 'fail';
        }

        $healthy = ! in_array('fail', $checks, true);

        return new JsonResponse([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'queue_size' => $queue,
            'time' => now()->utc()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
