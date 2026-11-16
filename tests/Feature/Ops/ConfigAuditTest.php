<?php

use App\Models\PlatformAudit;

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
