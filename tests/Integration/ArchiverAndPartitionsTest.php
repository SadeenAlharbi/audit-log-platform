<?php

use App\Models\Project;
use App\Support\Database\AuditLogPartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('lets the archiver account, and only it, delete audit logs (decision D3)', function () {
    $log = makeAuditLog(Project::factory()->create());

    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->delete())
        ->toThrow(QueryException::class, 'archiver account only');

    $deleted = DB::connection(config('audit.archiver.connection'))
        ->table('audit_logs')
        ->where('id', $log->id)
        ->delete();

    expect($deleted)->toBe(1);
});

it('does not let the archiver update audit logs', function () {
    $log = makeAuditLog(Project::factory()->create());

    expect(fn () => DB::connection(config('audit.archiver.connection'))
        ->table('audit_logs')->where('id', $log->id)->update(['event' => 'x']))
        ->toThrow(QueryException::class);
});

it('adds upcoming monthly partitions idempotently', function () {
    $ahead = (int) config('audit.partitions.ahead') + 3;

    $this->artisan('audit:partitions', ['--ahead' => $ahead])->assertSuccessful();

    $names = collect(app(AuditLogPartitionManager::class)->partitions())->pluck('name');
    $target = AuditLogPartitionManager::nameFor(CarbonImmutable::now('UTC')->startOfMonth()->addMonths($ahead));

    expect($names)->toContain($target)
        ->and($names->last())->toBe(AuditLogPartitionManager::FUTURE)
        ->and(app(AuditLogPartitionManager::class)->ensureAhead(CarbonImmutable::now('UTC'), $ahead))->toBe([]);
});
