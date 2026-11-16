<?php

namespace App\Filament\Auth;

use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction as BaseSetUpAction;
use Filament\Auth\MultiFactor\App\AppAuthentication as BaseAppAuthentication;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Actions;

/**
 * Filament's authenticator-app 2FA, with the setup action that skips the
 * "current password" field after a SAML sign-in (decision D15).
 */
class AppAuthentication extends BaseAppAuthentication
{
    /** Keyed, so its actions can be addressed (tests). */
    public function getManagementSchemaComponents(): array
    {
        $components = parent::getManagementSchemaComponents();
        if (($components[0] ?? null) instanceof Actions) {
            $components[0]->key('actions');
        }

        return $components;
    }

    public function getActions(): array
    {
        $user = Filament::auth()->user();
        $actions = parent::getActions();

        foreach ($actions as $i => $action) {
            if ($action->getName() === BaseSetUpAction::make($this)->getName()) {
                $actions[$i] = SetUpAppAuthenticationAction::make($this)
                    ->hidden(fn (): bool => $this->isEnabled($user));
            }
        }

        return $actions;
    }
}
