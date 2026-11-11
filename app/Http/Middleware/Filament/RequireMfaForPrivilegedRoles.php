<?php

namespace App\Http\Middleware\Filament;

use App\Models\User;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Plan §13: 2FA (TOTP) is mandatory for Super Admin and Security Officer.
 * Other users may enable it from their profile but are not forced to.
 */
class RequireMfaForPrivilegedRoles extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->requiresMultiFactorAuthentication()) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
