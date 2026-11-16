<?php

namespace App\Support;

use App\Models\User;
use Database\Seeders\DemoSeeder;

/**
 * The "demo accounts" list under the sign-in form: one click signs in as one
 * of the demo accounts (demo.test), without password or 2FA, to test the
 * roles quickly.
 *
 * Allowed only when all of these hold:
 *  - APP_ENV is local (on a local host: localhost, 127.0.0.1, *.localhost,
 *    *.test) or review;
 *  - AUDIT_DEMO_LOGINS is on (default);
 *  - the account exists (`make demo`, which itself refuses other environments).
 *
 * A production server (APP_ENV=production) never shows or accepts it, and a
 * copy cloned from GitHub only signs in to its own local demo data.
 */
class DemoLogins
{
    public const VIA = 'demo';

    public static function allowed(): bool
    {
        if (! config('audit.demo_logins')) {
            return false;
        }

        if (app()->environment('review')) {
            return true;
        }

        if (! app()->environment('local')) {
            return false;
        }

        $host = app()->runningInConsole() ? 'localhost' : request()->getHost();

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');
    }

    /** @return array<string, string> e-mail => role, for the accounts that exist */
    public static function accounts(): array
    {
        if (! self::allowed()) {
            return [];
        }

        $accounts = array_column(DemoSeeder::ACCOUNTS, 1, 0);
        $existing = User::query()->whereIn('email', array_keys($accounts))->pluck('email')->all();

        return array_intersect_key($accounts, array_flip($existing));
    }

    /** This session was opened from the demo list (2FA not asked). */
    public static function isDemoSession(): bool
    {
        return app()->bound('session') && session('auth.via') === self::VIA && self::allowed();
    }
}
