<?php

use App\Models\Project;
use App\Support\Database\AuditLogPartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('partitions audit_logs by month with the upcoming months ready', function () {
    $names = collect(app(AuditLogPartitionManager::class)->partitions())->pluck('name');
    $month = CarbonImmutable::now('UTC')->startOfMonth();

    expect($names->first())->toBe(AuditLogPartitionManager::BEFORE)
        ->and($names->last())->toBe(AuditLogPartitionManager::FUTURE);

    foreach (range(0, (int) config('audit.partitions.ahead')) as $i) {
        expect($names)->toContain(AuditLogPartitionManager::nameFor($month->addMonths($i)));
    }
});

it('stores each log in the partition of its occurred_at month', function () {
    $project = Project::factory()->create();
    $lastMonth = CarbonImmutable::now('UTC')->startOfMonth()->subMonth()->addDays(3);

    makeAuditLog($project, ['occurred_at' => $lastMonth]);

    $partition = AuditLogPartitionManager::nameFor($lastMonth->startOfMonth());
    $count = DB::selectOne("SELECT COUNT(*) AS c FROM audit_logs PARTITION ({$partition})")->c;

    expect((int) $count)->toBe(1);
});

it('has no foreign keys on the partitioned table (decision D1)', function () {
    $fks = DB::select(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );

    expect($fks)->toBeEmpty();
});
