<?php

use App\Services\Alerts\AlertNotifier;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks (run by the "scheduler" container: php artisan schedule:work)
|--------------------------------------------------------------------------
| Database backups (full daily, incremental + binary logs hourly, weekly
| restore test) run inside the "backup" container; backup:monitor imports
| their results and raises alerts.
*/

// Keep the next months of audit_logs partitions ready (idempotent).
// A failure here means next month's logs have nowhere to go: alert.
Schedule::command('audit:partitions')->dailyAt('01:10')->onOneServer()->withoutOverlapping()
    ->onFailure(fn () => app(AlertNotifier::class)->send('partitions:failed', 'Partition maintenance failed', 'php artisan audit:partitions failed; check the scheduler logs.', 'critical'));

// Write-ahead archive: every stored log goes to S3/MinIO as JSONL.gz.
// Monthly (plan §12): on the 1st, the month that just ended, before the nightly prune.
Schedule::command('audit:archive')->monthlyOn(1, '01:30')->onOneServer()->withoutOverlapping(360);

// Retention (D3): archive first, then drop expired partitions and delete
// rows older than each project's retention_days.
Schedule::command('audit:prune')->dailyAt('02:30')->onOneServer()->withoutOverlapping(360);

// Application files (.env + storage) and off-site retention.
Schedule::command('backup:run --type=app')->dailyAt('03:00')->onOneServer();
Schedule::command('backup:clean')->dailyAt('04:00')->onOneServer();
Schedule::command('backup:monitor')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

// Decision D16: settings live in .env; record any change in platform_audit.
Schedule::command('audit:config-check')->hourly()->onOneServer();

// Security and access.
Schedule::command('api-keys:expiring')->dailyAt('08:00')->onOneServer();
Schedule::command('access:review --sensitive-only')->monthlyOn(1, '07:00')->onOneServer();
Schedule::command('access:review')->quarterlyOn(1, '07:30')->onOneServer();

// Queues (plan §11): alert when jobs pile up; Horizon metrics.
Schedule::command('queue:monitor redis-ingest:ingest,redis:default --max='.(int) config('audit.alerts.queue_backlog_threshold', 5000))->everyFiveMinutes();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Plan §7 / D21: rejected requests over the per-minute sample are counted,
// then written here as one summary row per IP, reason and minute.
Schedule::command('audit:flush-security-events')->everyMinute()->onOneServer()->withoutOverlapping();

// Accepted (202) logs that never reached audit_logs: lost queue jobs.
Schedule::command('audit:check-ingestion')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
