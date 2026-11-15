<?php

use App\Console\Commands\BackupCleanCommand;
use App\Models\BackupRun;
use App\Models\PlatformAudit;
use App\Notifications\PlatformAlert;
use App\Services\Backup\AppFilesBackup;
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

it('includes the configuration file from BACKUP_ENV_FILE (production mount)', function () {
    Storage::fake('backups');
    $dir = storage_path('app/tmp/env-test-'.uniqid());
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/.env', "APP_NAME=Test\n");
    // Unencrypted here so the archive can be listed (encryption has its own test).
    config(['audit.backup.env_file' => $dir.'/.env', 'audit.backup.age_recipient' => null]);

    $this->artisan('backup:run --type=app')->assertSuccessful();

    $run = BackupRun::query()->where('type', 'app_files')->firstOrFail();
    expect($run->status)->toBe('succeeded')->and($run->meta['env_included'])->toBeTrue();

    $archive = tempnam(sys_get_temp_dir(), 'bk');
    file_put_contents($archive, Storage::disk('backups')->get(Storage::disk('backups')->allFiles('app')[0]));
    exec('tar -tzf '.escapeshellarg($archive), $entries);
    expect($entries)->toContain('.env');

    @unlink($dir.'/.env');
    @rmdir($dir);
    @unlink($archive);
});

it('fails loudly outside development when the configuration file is missing', function () {
    Storage::fake('backups');
    config(['audit.backup.env_file' => '/nonexistent/.env', 'audit.backup.age_recipient' => 'age1test']);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(AppFilesBackup::class)->run())->toThrow(RuntimeException::class, 'not readable');
    expect(BackupRun::query()->where('type', 'app_files')->value('status'))->toBe('failed');
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

it('records a manual full or point-in-time restore', function () {
    $this->artisan('backup:record-restore', ['kind' => 'pitr', '--stop-at' => '2026-11-11 09:59:59', '--by' => 'ops', '--reason' => 'INC-12'])
        ->assertSuccessful();

    $run = BackupRun::query()->where('type', 'restore')->sole();
    expect($run->meta)->toMatchArray(['kind' => 'pitr', 'stop_at' => '2026-11-11 09:59:59', 'reason' => 'INC-12'])
        ->and(PlatformAudit::query()->where('action', 'backup.restore_recorded')->exists())->toBeTrue();

    $this->artisan('backup:record-restore', ['kind' => 'oops'])->assertFailed();
});
