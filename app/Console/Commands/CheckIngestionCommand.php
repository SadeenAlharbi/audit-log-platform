<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds logs that were accepted (202) but never reached audit_logs.
 *
 * The API reserves the idempotency key (ingestion_keys) before the log is
 * queued. If the queued job is lost (Redis crash between two AOF syncs, a
 * failed job removed from failed_jobs) a client that retries the same
 * external_id gets `duplicate` for a log that does not exist. This check
 * makes that visible:
 *  - keys older than the grace period whose log_uid is not in audit_logs
 *    (the key's occurred_at equals the log's, so the lookup hits one partition)
 *  - batches still "queued" after the grace period (covers logs without
 *    external_id too).
 */
#[Signature('audit:check-ingestion
    {--grace= : Minutes a log may stay in the queue before it counts as missing}
    {--hours=24 : How far back to look}')]
#[Description('Alert when accepted logs are missing from audit_logs (lost queue jobs)')]
class CheckIngestionCommand extends Command
{
    public function handle(AlertNotifier $alerts): int
    {
        $grace = (int) ($this->option('grace') ?? config('audit.api.ingest_check_grace_minutes', 30));
        $until = now()->utc()->subMinutes(max(1, $grace))->format('Y-m-d H:i:s.v');
        $since = now()->utc()->subHours(max(1, (int) $this->option('hours')))->format('Y-m-d H:i:s.v');

        $missing = DB::table('ingestion_keys as k')
            ->join('projects as p', 'p.id', '=', 'k.project_id')
            ->whereBetween('k.created_at', [$since, $until])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('audit_logs as l')
                ->whereColumn('l.uid', 'k.log_uid')
                ->whereColumn('l.occurred_at', 'k.occurred_at'))
            ->groupBy('p.slug')
            ->orderBy('p.slug')
            ->selectRaw('p.slug, COUNT(*) AS missing, MIN(k.created_at) AS first_at')
            ->get();

        $stuck = (int) DB::table('ingestion_batches')
            ->where('status', 'queued')
            ->where('accepted_count', '>', 0)
            ->whereBetween('created_at', [$since, $until])
            ->count();

        $total = (int) $missing->sum('missing');

        if ($total === 0 && $stuck === 0) {
            $this->components->info('All accepted logs are stored.');

            return self::SUCCESS;
        }

        $this->table(['Project', 'Missing logs', 'First accepted at (UTC)'], $missing->map(fn ($r) => [$r->slug, $r->missing, $r->first_at])->all());
        $this->components->warn("{$total} accepted log(s) with external_id are missing; {$stuck} batch(es) still queued after {$grace} minutes.");

        $alerts->send(
            key: 'ingest:missing',
            title: 'Accepted audit logs are missing',
            message: "{$total} log(s) accepted more than {$grace} minutes ago are not in audit_logs and {$stuck} batch(es) are still queued. "
                .'Check `php artisan queue:failed` and Horizon; see the runbook (lost ingest jobs).',
            level: 'critical',
            context: ['projects' => $missing->pluck('missing', 'slug')->all(), 'stuck_batches' => $stuck],
        );

        return self::FAILURE;
    }
}
