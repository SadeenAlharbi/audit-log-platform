<?php

use App\Models\BackupRun;
use App\Models\Project;
use App\Services\Archive\LogArchiver;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('archive');
    config(['audit.archive.settle_seconds' => 0]);
});

it('archives stored logs as gzip JSONL per project with a manifest', function () {
    $a = Project::factory()->create(['slug' => 'erp']);
    $b = Project::factory()->create(['slug' => 'crm']);
    makeAuditLog($a, ['new_values' => ['name' => 'سارة']]);
    makeAuditLog($a);
    makeAuditLog($b);

    $this->artisan('audit:archive')->assertSuccessful();

    $files = Storage::disk('archive')->allFiles('logs');
    expect(collect($files)->filter(fn ($f) => str_ends_with($f, '.jsonl.gz')))->toHaveCount(2);

    $erp = collect($files)->first(fn ($f) => str_starts_with($f, 'logs/erp/') && str_ends_with($f, '.jsonl.gz'));
    $lines = explode("\n", trim(gzdecode(Storage::disk('archive')->get($erp))));
    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true)['new_values'])->toBe(['name' => 'سارة']);

    $manifest = json_decode(Storage::disk('archive')->get(substr($erp, 0, -9).'.json'), true);
    expect($manifest)->rows->toBe(2)->project->toBe('erp')
        ->and($manifest['sha256'])->toBe(hash('sha256', Storage::disk('archive')->get($erp)));
});

it('continues from the watermark and never archives twice', function () {
    $project = Project::factory()->create();
    makeAuditLog($project);
    $this->artisan('audit:archive')->assertSuccessful();
    $first = LogArchiver::watermark();

    $this->artisan('audit:archive')->assertSuccessful();
    expect(BackupRun::query()->where('type', 'logs_archive')->latest('id')->first()->meta['rows'])->toBe(0);

    makeAuditLog($project);
    $this->artisan('audit:archive')->assertSuccessful();
    expect(LogArchiver::watermark())->toBeGreaterThan($first);
});

it('waits for recent rows to settle', function () {
    config(['audit.archive.settle_seconds' => 3600]);
    makeAuditLog(Project::factory()->create());

    $this->artisan('audit:archive')->assertSuccessful();

    expect(LogArchiver::watermark())->toBe(0);
});
