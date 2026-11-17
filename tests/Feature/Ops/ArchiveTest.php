<?php

use App\Models\BackupRun;
use App\Models\Project;
use App\Services\Archive\LogArchiver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('archive');
    config(['audit.archive.settle_seconds' => 0]);
});

/** A moment in the previous calendar month (UTC): complete, so it is archived. */
function inLastMonth(int $day = 10): CarbonImmutable
{
    return CarbonImmutable::now('UTC')->startOfMonth()->subMonth()->addDays($day - 1)->setTime(12, 0);
}

it('archives stored logs as gzip JSONL per project and month with a manifest', function () {
    $a = Project::factory()->create(['slug' => 'erp']);
    $b = Project::factory()->create(['slug' => 'crm']);
    makeAuditLog($a, ['new_values' => ['name' => 'سارة'], 'created_at' => inLastMonth(3)]);
    makeAuditLog($a, ['created_at' => inLastMonth(20)]);
    makeAuditLog($b, ['created_at' => inLastMonth(5)]);

    $this->artisan('audit:archive')->assertSuccessful();

    $files = Storage::disk('archive')->allFiles('logs');
    expect(collect($files)->filter(fn ($f) => str_ends_with($f, '.jsonl.gz')))->toHaveCount(2);

    $erp = collect($files)->first(fn ($f) => str_starts_with($f, 'logs/erp/') && str_ends_with($f, '.jsonl.gz'));
    // One folder per month: logs/erp/YYYY/MM/{first}-{last}.jsonl.gz
    expect($erp)->toStartWith('logs/erp/'.inLastMonth()->format('Y/m').'/')
        ->and(substr_count($erp, '/'))->toBe(4);
    $lines = explode("\n", trim(gzdecode(Storage::disk('archive')->get($erp))));
    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true)['new_values'])->toBe(['name' => 'سارة']);

    $manifest = json_decode(Storage::disk('archive')->get(substr($erp, 0, -9).'.json'), true);
    expect($manifest)->rows->toBe(2)->project->toBe('erp')
        ->and($manifest['sha256'])->toBe(hash('sha256', Storage::disk('archive')->get($erp)));
});

it('leaves the current month for next month\'s run', function () {
    $project = Project::factory()->create();
    makeAuditLog($project, ['created_at' => inLastMonth()]);
    makeAuditLog($project); // stored now, in the current month

    $this->artisan('audit:archive')->assertSuccessful();

    expect(BackupRun::query()->where('type', 'logs_archive')->latest('id')->first()->meta['rows'])->toBe(1);

    // On the 1st of next month the current month is complete too.
    $this->travelTo(CarbonImmutable::now('UTC')->startOfMonth()->addMonth()->addHours(2));
    $this->artisan('audit:archive')->assertSuccessful();
    expect(BackupRun::query()->where('type', 'logs_archive')->latest('id')->first()->meta['rows'])->toBe(1)
        ->and(collect(Storage::disk('archive')->allFiles('logs/'.$project->slug))->filter(fn ($f) => str_ends_with($f, '.jsonl.gz')))->toHaveCount(2);
});

it('continues from the watermark and never archives twice', function () {
    $project = Project::factory()->create();
    makeAuditLog($project, ['created_at' => inLastMonth(3)]);
    $this->artisan('audit:archive')->assertSuccessful();
    $first = LogArchiver::watermark();

    $runs = BackupRun::query()->where('type', 'logs_archive')->count();
    $this->artisan('audit:archive')->assertSuccessful();
    // Nothing new: no empty run is recorded and the watermark does not move.
    expect(BackupRun::query()->where('type', 'logs_archive')->count())->toBe($runs)
        ->and(LogArchiver::watermark())->toBe($first);

    makeAuditLog($project, ['created_at' => inLastMonth(20)]);
    $this->artisan('audit:archive')->assertSuccessful();
    expect(LogArchiver::watermark())->toBeGreaterThan($first);
});

it('waits for recent rows to settle', function () {
    config(['audit.archive.settle_seconds' => 3600]);
    // A row stored just before the month ended, archived right after it began.
    $monthStart = CarbonImmutable::now('UTC')->startOfMonth();
    makeAuditLog(Project::factory()->create(), ['created_at' => $monthStart->subMinutes(5)]);
    $this->travelTo($monthStart->addMinutes(10));

    $this->artisan('audit:archive')->assertSuccessful();

    expect(LogArchiver::watermark())->toBe(0);
});

it('archives monthly on the 1st, before the nightly prune (plan §12)', function () {
    $events = collect(app(Schedule::class)->events());
    $archive = $events->first(fn ($e) => str_contains((string) $e->command, 'audit:archive'));
    $prune = $events->first(fn ($e) => str_contains((string) $e->command, 'audit:prune'));

    expect($archive->expression)->toBe('30 1 1 * *')
        ->and($prune->expression)->toBe('30 2 * * *');
});
