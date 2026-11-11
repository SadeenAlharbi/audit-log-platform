<?php

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
Schedule::command('audit:partitions')->dailyAt('01:10')->onOneServer()->withoutOverlapping();

// Write-ahead archive: every stored log goes to S3/MinIO as JSONL.gz.
Schedule::command('audit:archive')->hourlyAt(5)->onOneServer()->withoutOverlapping(120);

// Retention (D3): archive first, then drop expired partitions and delete
// rows older than each project's retention_days.
Schedule::command('audit:prune')->dailyAt('02:30')->onOneServer()->withoutOverlapping(360);

// Application files (.env + storage) and off-site retention.
Schedule::command('backup:run --type=app')->dailyAt('03:00')->onOneServer();
Schedule::command('backup:clean')->dailyAt('04:00')->onOneServer();
Schedule::command('backup:monitor')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

// Security and access.
Schedule::command('api-keys:expiring')->dailyAt('08:00')->onOneServer();
Schedule::command('access:review --sensitive-only')->monthlyOn(1, '07:00')->onOneServer();
Schedule::command('access:review')->quarterlyOn(1, '07:30')->onOneServer();

// Queues (plan §11): alert when jobs pile up; Horizon metrics.
Schedule::command('queue:monitor redis:ingest,redis:default --max='.(int) config('audit.alerts.queue_backlog_threshold', 5000))->everyFiveMinutes();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
