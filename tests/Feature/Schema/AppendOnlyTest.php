<?php

use App\Exceptions\AppendOnlyViolation;
use App\Models\PlatformAudit;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects UPDATE on audit_logs at the database level', function () {
    $log = makeAuditLog(Project::factory()->create());

    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['event' => 'tampered']))
        ->toThrow(QueryException::class, 'append-only');
});

it('rejects DELETE on audit_logs for the application account', function () {
    $log = makeAuditLog(Project::factory()->create());

    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->delete())
        ->toThrow(QueryException::class, 'archiver account only');
});

it('blocks updates and deletes through the AuditLog model too', function () {
    $log = makeAuditLog(Project::factory()->create());

    expect(fn () => $log->update(['event' => 'tampered']))->toThrow(AppendOnlyViolation::class)
        ->and(fn () => $log->delete())->toThrow(AppendOnlyViolation::class);
});

it('keeps platform_audit fully immutable', function () {
    $entry = PlatformAudit::query()->create(['action' => 'role.updated', 'changes' => ['x' => 1]]);

    expect(fn () => DB::table('platform_audit')->where('id', $entry->id)->update(['action' => 'x']))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('platform_audit')->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class, 'append-only');
});
