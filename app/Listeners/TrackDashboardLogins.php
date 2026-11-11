<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Audit\PlatformAuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Plan §13: lock the account after repeated failed logins, and keep a trail
 * of dashboard sign-ins in platform_audit.
 */
class TrackDashboardLogins
{
    public function __construct(private PlatformAuditLogger $audit) {}

    public function handle(Login|Logout|Failed $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        match (true) {
            $event instanceof Failed => $this->failed($event),
            $event instanceof Login => $this->login($event),
            default => $event->user instanceof User ? $this->audit->record('auth.logout', $event->user, [], $event->user) : null,
        };
    }

    private function failed(Failed $event): void
    {
        $user = $event->user instanceof User
            ? $event->user
            : User::query()->where('email', $event->credentials['email'] ?? null)->first();

        if ($user === null) {
            $this->audit->record('auth.login_failed', 'unknown_user', ['email' => mb_substr((string) ($event->credentials['email'] ?? ''), 0, 191)]);

            return;
        }

        $attempts = $user->failed_login_attempts + 1;
        $max = (int) config('audit.accounts.max_failed_logins', 5);
        $lock = $attempts >= $max;

        $user->forceFill([
            'failed_login_attempts' => $lock ? 0 : $attempts,
            'locked_until' => $lock ? now()->addMinutes((int) config('audit.accounts.lockout_minutes', 15)) : $user->locked_until,
        ])->saveQuietly();

        $this->audit->record($lock ? 'auth.account_locked' : 'auth.login_failed', $user, ['attempts' => $attempts]);
    }

    private function login(Login $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || $user->isLocked()) {
            return; // a locked account is rejected by canAccessPanel()
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->saveQuietly();

        $this->audit->record('auth.login', $user, [], $user);
    }
}
