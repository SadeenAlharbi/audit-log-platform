<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\PlatformAuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Lifts a lockout from the server, for when no other administrator can use
 * the "unlock" action in the dashboard (e.g. the only Super Admin is locked).
 * Recorded in platform_audit like the dashboard action.
 */
#[Signature('audit:unlock-user {email : The account e-mail}')]
#[Description('Unlock a dashboard account locked after repeated failed sign-ins')]
class UnlockUserCommand extends Command
{
    public function handle(PlatformAuditLogger $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account with the e-mail {$email}.");

            return self::FAILURE;
        }

        if (! $user->isLocked() && $user->failed_login_attempts === 0) {
            $this->info("{$email} is not locked.");

            return self::SUCCESS;
        }

        $user->forceFill(['locked_until' => null, 'failed_login_attempts' => 0])->saveQuietly();
        $audit->record('user.unlocked', $user, ['via' => 'console']);
        $this->info("{$email} unlocked.");

        return self::SUCCESS;
    }
}
