<?php

use App\Console\Commands\BackupCleanCommand;
use App\Models\BackupRun;
use App\Notifications\PlatformAlert;
use App\Services\Backup\BackupSpool;
use App\Services\Backup\GfsRetention;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->spool = storage_path('framework/testing/spool-'.uniqid());
    config(['audit.backup.spool_path' => $this->spool, 'audit.alerts.mail_to' => ['ops@example.com']]);
    Notification::fake();
});

afterEach(fn () => File::deleteDirectory($this->spool));

it('keeps 14 daily, 8 weekly and 12 monthly backups', function () {
    $now = CarbonImmutable::parse('2026-11-11 03:00', 'UTC');
    $backups = [];
    for ($i = 0; $i < 400; $i++) {
        $at = $now->subDays($i);
        $backups["db/full/full-{$at->format('Ymd\THis\Z')}.xb.gz"] = $at;
    }

    $deleted = GfsRetention::toDelete($backups, 14, 8, 12, $now);
    $kept = array_diff(array_keys($backups), $deleted);

    expect(count($kept))->toBeGreaterThanOrEqual(14)->toBeLessThanOrEqual(14 + 8 + 12)
        ->and($kept)->toContain('db/full/full-20261111T030000Z.xb.gz')
        ->and($deleted)->toContain('db/full/full-'.$now->subDays(399)->format('Ymd\THis\Z').'.xb.gz');
});

it('writes a request file for the backup container', function () {
    $file = app(BackupSpool::class)->request('full');

    expect($file)->toStartWith($this->spool.'/requests/full-')
        ->and(json_decode(file_get_contents($file), true)['type'])->toBe('full');
});

it('imports backup results and alerts on failure', function () {
    File::ensureDirectoryExists($this->spool.'/status');
    File::put($this->spool.'/status/a.json', json_encode([
        'id' => 'run-1', 'type' => 'full', 'status' => 'succeeded', 'size' => 1000,
        'started_at' => now()->subMinutes(5)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'location' => 's3://backups/db/full/x', 'checksum' => str_repeat('a', 64),
    ]));
    File::put($this->spool.'/status/b.json', json_encode([
        'id' => 'run-2', 'type' => 'incremental', 'status' => 'failed', 'error' => 'disk full',
        'started_at' => now()->toIso8601String(),
    ]));

    $this->artisan('backup:monitor')->assertSuccessful();

    expect(BackupRun::query()->where('type', 'full')->where('status', 'succeeded')->exists())->toBeTrue()
        ->and(BackupRun::query()->where('type', 'incremental')->where('status', 'failed')->value('error'))->toBe('disk full')
        ->and(glob($this->spool.'/status/*.json'))->toBe([]);

    Notification::assertSentOnDemand(PlatformAlert::class, fn ($n) => str_contains($n->title, 'Backup failed: incremental'));
});

it('alerts when backups are late', function () {
    $this->artisan('backup:monitor')->assertSuccessful();

    Notification::assertSentOnDemand(PlatformAlert::class, fn ($n) => $n->title === 'Backup is late');
});

it('backs up the application files to the backups disk', function () {
    Storage::fake('backups');

    $this->artisan('backup:run --type=app')->assertSuccessful();

    $run = BackupRun::query()->where('type', 'app_files')->firstOrFail();
    expect($run->status)->toBe('succeeded')->and($run->checksum)->toHaveLength(64);
    expect(Storage::disk('backups')->allFiles('app'))->toHaveCount(1);
});

it('applies retention to off-site backups', function () {
    Storage::fake('backups');
    $disk = Storage::disk('backups');
    $old = now()->utc()->subYears(2)->format('Ymd\THis\Z');
    $new = now()->utc()->format('Ymd\THis\Z');
    $disk->put("db/full/full-{$old}.xb.gz.age", 'x');
    $disk->put("db/full/full-{$new}.xb.gz.age", 'x');
    $disk->put("db/incremental/inc-{$old}.xb.gz.age", 'x');

    $this->artisan('backup:clean')->assertSuccessful();

    expect($disk->allFiles('db'))->toBe(["db/full/full-{$new}.xb.gz.age"]);
    expect(BackupCleanCommand::timestamp("x/full-{$new}.age")->format('Ymd\THis\Z'))->toBe($new);
});
