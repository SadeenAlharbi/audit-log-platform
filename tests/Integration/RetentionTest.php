<?php

use App\Models\AuditLog;
use App\Models\Project;
use App\Support\Database\AuditLogPartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('archive');
    // The soft-archive grace period has its own test below.
    config(['audit.archive.settle_seconds' => 0, 'audit.archive.grace_days' => 0]);
});

/**
 * An expired log: occurred and stored in a complete earlier month, so the
 * monthly archive (plan §12) takes it and the 7-day retention has passed.
 *
 * @param  array<string, mixed>  $overrides
 */
function expiredLog(Project $project, array $overrides = []): AuditLog
{
    $at = CarbonImmutable::now('UTC')->startOfMonth()->subDays(20);
    // A project cannot store logs before it exists (the archive restore only
    // looks from the project's creation on).
    if ($project->created_at === null || $project->created_at->greaterThan($at)) {
        $project->forceFill(['created_at' => $at->subDay()])->saveQuietly();
    }

    return makeAuditLog($project, ['occurred_at' => $at, 'created_at' => $at, ...$overrides]);
}

it('archives then deletes logs older than the project retention through the archiver account', function () {
    $short = Project::factory()->create(['retention_days' => 7]);
    $long = Project::factory()->create(['retention_days' => 365]);

    expiredLog($short);
    makeAuditLog($short, ['occurred_at' => now()]);
    expiredLog($long);

    $this->artisan('audit:prune')->assertSuccessful();

    expect(AuditLog::query()->withoutGlobalScopes()->where('project_id', $short->id)->count())->toBe(1)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('project_id', $long->id)->count())->toBe(1)
        ->and(Storage::disk('archive')->allFiles('logs/'.$short->slug))->not->toBeEmpty();
});

it('never deletes rows that are not archived yet', function () {
    $project = Project::factory()->create(['retention_days' => 7]);
    expiredLog($project);

    $this->artisan('audit:prune --skip-archive')->assertSuccessful();

    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('keeps expired rows of the current month until the monthly archive has taken them', function () {
    // Received this month with an old occurred_at (a late client): not archived yet, so not deleted.
    if (CarbonImmutable::now('UTC')->day === 1) {
        $this->travelTo(CarbonImmutable::now('UTC')->addDay());
    }
    $project = Project::factory()->create(['retention_days' => 7]);
    makeAuditLog($project, ['occurred_at' => now()->utc()->subDays(20)]);

    $this->artisan('audit:prune')->assertSuccessful();

    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('keeps idempotency keys while their log rows still exist', function () {
    $project = Project::factory()->create(['retention_days' => 7]);
    $log = expiredLog($project, ['external_id' => 'kept']);
    $old = CarbonImmutable::instance($log->occurred_at);
    DB::table('ingestion_keys')->insert(['project_id' => $project->id, 'external_id' => 'kept', 'log_uid' => $log->uid, 'occurred_at' => $old->format('Y-m-d H:i:s.v'), 'created_at' => $old->format('Y-m-d H:i:s.v')]);

    // Not archived yet: the row stays, and so must its key.
    $this->artisan('audit:prune --skip-archive')->assertSuccessful();

    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(DB::table('ingestion_keys')->where('external_id', 'kept')->exists())->toBeTrue();
});

it('drops monthly partitions older than the platform maximum', function () {
    // Pretend the platform keeps 1 day: last month's partition is expired.
    // On the 1st of a month last month is still within 1 day: move forward.
    if (CarbonImmutable::now('UTC')->day === 1) {
        $this->travelTo(CarbonImmutable::now('UTC')->addDay());
    }
    config(['audit.retention.max_days' => 1]);
    $project = Project::factory()->create(['retention_days' => 1]);
    $lastMonth = CarbonImmutable::now('UTC')->startOfMonth()->subMonth()->addDays(2);
    makeAuditLog($project, ['occurred_at' => $lastMonth, 'created_at' => $lastMonth, 'external_id' => 'k1']);
    DB::table('ingestion_keys')->insert(['project_id' => $project->id, 'external_id' => 'k1', 'log_uid' => 'x', 'occurred_at' => $lastMonth->format('Y-m-d H:i:s.v'), 'created_at' => $lastMonth->format('Y-m-d H:i:s.v')]);

    $this->artisan('audit:prune')->assertSuccessful();

    $names = collect(app(AuditLogPartitionManager::class)->partitions())->pluck('name');
    expect($names)->not->toContain(AuditLogPartitionManager::nameFor($lastMonth->startOfMonth()))
        ->and(DB::table('ingestion_keys')->count())->toBe(0);
});

it('restores one project from the archive without duplicates', function () {
    $project = Project::factory()->create(['retention_days' => 7]);
    $log = expiredLog($project, ['new_values' => ['a' => 1], 'external_id' => 'order-1']);
    DB::table('ingestion_keys')->insert(['project_id' => $project->id, 'external_id' => 'order-1', 'log_uid' => $log->uid, 'occurred_at' => $log->getRawOriginal('occurred_at'), 'created_at' => $log->getRawOriginal('occurred_at')]);
    $this->artisan('audit:prune')->assertSuccessful();
    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('ingestion_keys')->count())->toBe(0);

    $this->artisan('audit:restore-archive', ['project' => $project->slug, '--from' => now()->startOfMonth()->subDays(30)->toDateString(), '--to' => now()->toDateString()])
        ->assertSuccessful();
    $this->artisan('audit:restore-archive', ['project' => $project->slug])->assertSuccessful();

    $restored = AuditLog::query()->withoutGlobalScopes()->get();
    expect($restored)->toHaveCount(1)
        ->and($restored->first()->uid)->toBe($log->uid)
        ->and($restored->first()->new_values)->toBe(['a' => 1])
        // The idempotency key is back: a retry of order-1 is still a duplicate.
        ->and(DB::table('ingestion_keys')->where('external_id', 'order-1')->value('log_uid'))->toBe($log->uid);
});

it('keeps archived rows during the soft-archive grace period', function () {
    config(['audit.archive.grace_days' => 7]);
    $project = Project::factory()->create(['retention_days' => 7]);
    expiredLog($project);

    $this->artisan('audit:prune')->assertSuccessful();
    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(Storage::disk('archive')->allFiles('logs/'.$project->slug))->not->toBeEmpty();

    // Eight days later the archive run is old enough: the row goes.
    $this->travel(8)->days();
    $this->artisan('audit:prune --skip-archive')->assertSuccessful();
    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('does not delete restored logs again while their hold lasts', function () {
    $project = Project::factory()->create(['retention_days' => 7]);
    expiredLog($project);
    $this->artisan('audit:prune')->assertSuccessful();
    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->artisan('audit:restore-archive', ['project' => $project->slug, '--hold-days' => 5])->assertSuccessful();
    $this->artisan('audit:prune')->assertSuccessful();
    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(1);

    // After the hold, the normal retention applies again.
    $this->travel(6)->days();
    $this->artisan('audit:prune --skip-archive')->assertSuccessful();
    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(0);
});
