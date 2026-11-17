<?php

use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Project;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('seeds roles and permissions idempotently', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Role::query()->count())->toBe(count(RolesAndPermissionsSeeder::ROLES))
        ->and(Permission::query()->count())->toBe(count(RolesAndPermissionsSeeder::PERMISSIONS))
        ->and(Role::findByName('super_admin')->permissions)->toHaveCount(count(RolesAndPermissionsSeeder::PERMISSIONS))
        ->and(Role::findByName('project_viewer')->hasPermissionTo('logs.view_sensitive'))->toBeFalse();
});

it('seeds an idempotent demo environment with one account per role', function () {
    $this->seed(DemoSeeder::class);
    $logs = AuditLog::query()->withoutGlobalScopes()->count();
    $this->seed(DemoSeeder::class);

    expect($logs)->toBe(810)
        ->and(AuditLog::query()->withoutGlobalScopes()->count())->toBe(810)
        ->and(Project::query()->withoutGlobalScopes()->count())->toBe(4)
        ->and(User::query()->where('email', 'like', '%@demo.test')->count())->toBe(5)
        // Rejected attempts (with D21 summary rows) and backup history, seeded once.
        ->and(SecurityEvent::query()->count())->toBe(16)
        ->and(SecurityEvent::query()->get()->sum(fn ($e) => $e->request_meta['suppressed'] ?? 1))->toBe(14 + 412 + 168)
        ->and(BackupRun::query()->count())->toBe(7);

    // The project viewer only sees the student-affairs system.
    $this->actingAs(User::query()->where('email', 'viewer@demo.test')->firstOrFail());
    expect(AuditLog::query()->distinct()->pluck('project_id')->all())
        ->toBe([Project::query()->withoutGlobalScopes()->where('slug', 'sis')->value('id')]);
});

it('tops up the demo logs on a later day so the dashboard has recent data', function () {
    $this->seed(DemoSeeder::class);
    $this->travel(2)->days();
    $this->seed(DemoSeeder::class);

    $recent = AuditLog::query()->withoutGlobalScopes()->where('occurred_at', '>=', now()->subDay())->count();
    expect(AuditLog::query()->withoutGlobalScopes()->count())->toBeGreaterThan(810)->toBeLessThan(810 + 200)
        ->and($recent)->toBeGreaterThan(0);
});

it('refuses to seed demo data in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoSeeder::class)->run())->toThrow(RuntimeException::class, 'development');
});
