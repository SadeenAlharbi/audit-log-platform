<?php

use App\Exceptions\AppendOnlyViolation;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('never deletes projects because audit_logs has no FK to them', function () {
    $project = Project::factory()->create();

    expect(fn () => $project->delete())->toThrow(AppendOnlyViolation::class)
        ->and(fn () => DB::table('projects')->where('id', $project->id)->delete())
        ->toThrow(QueryException::class, 'cannot be deleted');
});

it('archives a project instead of deleting it', function () {
    $project = Project::factory()->create();

    $project->archive();

    expect($project->fresh())
        ->is_active->toBeFalse()
        ->archived_at->not->toBeNull();
});

it('rejects a retention_days outside the allowed range', function () {
    expect(fn () => Project::factory()->create(['retention_days' => 0]))
        ->toThrow(QueryException::class);
});

it('caps the effective retention at the platform maximum (decision D3)', function () {
    config(['audit.retention.max_days' => 395]);

    $project = Project::factory()->create(['retention_days' => 1000]);

    expect($project->effectiveRetentionDays())->toBe(395);
});
