<?php

use App\Filament\Support\Labels;
use App\Filament\Support\PlatformAuditChanges;
use App\Models\PlatformAudit;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('records the settings once, then only what changed, never secret values', function () {
    config(['audit.alerts.slack_webhook_url' => 'https://hooks.slack.test/secret-path']);

    $this->artisan('audit:config-check')->assertSuccessful();
    $this->artisan('audit:config-check')->assertSuccessful(); // nothing changed

    expect(PlatformAudit::query()->where('action', 'config.baseline')->count())->toBe(1)
        ->and(PlatformAudit::query()->where('action', 'config.changed')->count())->toBe(0);

    config(['audit.retention.max_days' => 30, 'audit.alerts.slack_webhook_url' => 'https://hooks.slack.test/other']);
    $this->artisan('audit:config-check')->assertSuccessful();

    $change = PlatformAudit::query()->where('action', 'config.changed')->sole();
    expect($change->changes['changed'])->toContain('audit.retention.max_days', 'audit.alerts.slack_webhook_url')
        ->and($change->changes['values']['audit.retention.max_days'])->toBe(30)
        ->and(json_encode(PlatformAudit::query()->pluck('changes')))->not->toContain('secret-path')->not->toContain('hooks.slack.test/other');
});

it('shows a configuration change as before / after rows of the changed settings only', function () {
    $this->artisan('audit:config-check')->assertSuccessful();
    config(['audit.retention.max_days' => 30]);
    $this->artisan('audit:config-check')->assertSuccessful();

    $baseline = PlatformAudit::query()->where('action', 'config.baseline')->sole();
    $change = PlatformAudit::query()->where('action', 'config.changed')->sole();

    expect(PlatformAuditChanges::diffRows($baseline->action, $baseline->changes))->toBeNull()
        ->and(PlatformAuditChanges::details($baseline->action, $baseline->changes))->toHaveKey('audit.retention.max_days')
        ->and(PlatformAuditChanges::diffRows($change->action, $change->changes))->toBe([
            ['key' => 'audit.retention.max_days', 'old' => (string) $baseline->changes['values']['audit.retention.max_days'], 'new' => '30', 'status' => 'changed'],
        ]);
});

it('reads the other platform audit shapes', function () {
    expect(PlatformAuditChanges::diffRows('user.updated', ['name' => ['old' => 'A', 'new' => 'B']]))
        ->toBe([['key' => 'الاسم', 'old' => 'A', 'new' => 'B', 'status' => 'changed']])
        ->and(PlatformAuditChanges::diffRows('api_key.allowed_ips_updated', ['old' => [], 'new' => ['10.0.0.1']]))
        ->toBe([['key' => 'عناوين IP المسموحة', 'old' => '[]', 'new' => '["10.0.0.1"]', 'status' => 'changed']])
        ->and(PlatformAuditChanges::diffRows('logs.exported', ['format' => 'csv', 'filters' => ['severity' => 'critical']]))->toBeNull()
        ->and(PlatformAuditChanges::details('logs.exported', ['format' => 'csv', 'filters' => ['severity' => 'critical']]))
        ->toBe(['الصيغة' => 'csv', 'filters.severity' => 'critical']);
});

it('names the roles in the record when they are assigned by id', function () {
    $role = Role::findOrCreate('security_officer', 'web');
    $user = User::factory()->create();
    $user->syncRoles([$role->id]);

    $record = PlatformAudit::query()->where('action', 'role.assigned')->latest('id')->firstOrFail();

    expect($record->changes['items'])->toBe(['security_officer'])
        ->and(PlatformAuditChanges::details('role.assigned', ['items' => [$role->id]]))
        ->toBe(['الأدوار' => Labels::role('security_officer').' (security_officer)']);
});
