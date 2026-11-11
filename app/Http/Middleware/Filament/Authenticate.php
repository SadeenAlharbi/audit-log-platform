<?php

namespace App\Http\Middleware\Filament;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Auth\AuthenticationException;

/**
 * Filament's Authenticate, plus: a user who was deactivated or locked while
 * signed in is signed out and sent to the login page (instead of a 403).
 */
class Authenticate extends FilamentAuthenticate
{
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && (! $user->is_active || $user->isLocked())) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException('Account disabled.', $guards, $this->redirectTo($request));
        }

        parent::authenticate($request, $guards);
    }
}
