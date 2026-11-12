<?php

use App\Exceptions\AppendOnlyViolation;
use App\Models\PlatformAudit;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PlatformAlert;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['audit.alerts.mail_to' => ['ops@example.com']]);
    Notification::fake();
});

it('alerts once when one IP is rejected repeatedly', function () {
    config(['audit.security.alert_threshold' => 3]);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/logs', [])->assertStatus(401);
    }

    Notification::assertSentOnDemandTimes(PlatformAlert::class, 1);
});

it('warns about keys that expire soon', function () {
    $soon = issueKey()->key;
    $soon->forceFill(['expires_at' => now()->addDays(5)])->save();
    issueKey(); // expires in 90 days

    $this->artisan('api-keys:expiring')->assertSuccessful();

    Notification::assertSentOnDemandTimes(PlatformAlert::class, 1);
});

it('locks a dashboard account after 5 failed logins', function () {
    $user = User::factory()->create(['email' => 'x@example.com']);

    foreach (range(1, 5) as $i) {
        event(new Failed('web', $user, ['email' => 'x@example.com']));
    }

    expect($user->fresh()->isLocked())->toBeTrue()
        ->and(PlatformAudit::query()->where('action', 'auth.account_locked')->exists())->toBeTrue();
});

it('does not extend a lockout while the account is locked', function () {
    $user = User::factory()->create(['email' => 'x@example.com']);
    foreach (range(1, 5) as $i) {
        event(new Failed('web', $user, ['email' => 'x@example.com']));
    }
    $lockedUntil = $user->fresh()->locked_until;

    $this->travel(5)->minutes();
    foreach (range(1, 10) as $i) {
        event(new Failed('web', $user->fresh(), ['email' => 'x@example.com']));
    }

    expect($user->fresh()->locked_until->equalTo($lockedUntil))->toBeTrue();
});

it('never stores what was typed for an unknown account', function () {
    event(new Failed('web', null, ['email' => 'MyS3cretPassw0rd']));

    $entry = PlatformAudit::query()->where('action', 'auth.login_failed')->latest('id')->firstOrFail();

    expect(json_encode($entry->changes))->not->toContain('MyS3cretPassw0rd')
        ->and($entry->changes['identifier_hash'])->toHaveLength(16);
});

it('records role assignments in platform_audit', function () {
    $user = platformUser('platform_auditor');

    expect(PlatformAudit::query()->where('action', 'role.assigned')->where('target_id', (string) $user->id)->exists())->toBeTrue();
});

it('refuses to delete platform_audit rows, projects and keys', function () {
    $project = Project::factory()->create();
    expect(fn () => $project->delete())->toThrow(AppendOnlyViolation::class)
        ->and(fn () => issueKey($project)->key->delete())->toThrow(AppendOnlyViolation::class);
});

it('generates the access review and mails it to super admins', function () {
    Mail::fake();
    platformUser('super_admin');
    projectUser(Project::factory()->create(['slug' => 'erp']), 'project_owner');

    $this->artisan('access:review')->assertSuccessful();

    $csv = collect(Storage::disk('local')->allFiles('access-reviews'))->last();
    expect(Storage::disk('local')->get($csv))
        ->toContain('super_admin')
        ->toContain('erp:project_owner');
});
