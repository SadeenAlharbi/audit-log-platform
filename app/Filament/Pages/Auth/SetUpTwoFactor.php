<?php

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The "set up 2FA" page an account is sent to when 2FA is required and not
 * set up yet. Adds a way out (sign out) and, after a SAML sign-in, says why
 * the page is shown.
 */
class SetUpTwoFactor extends SetUpRequiredMultiFactorAuthentication
{
    public function getSubheading(): string|Htmlable|null
    {
        $user = Filament::auth()->user();
        $who = $user ? ' (حسابك: '.$user->email.')' : '';

        return session('auth.via') === 'saml'
            ? 'دخلت عبر SAML'.$who.'. المنصة تطلب أيضًا رمزًا من تطبيق المصادقة عند كل دخول، فأعدّه مرة واحدة الآن.'
            : parent::getSubheading();
    }

    public function getFooterActionsContentComponent(): Component
    {
        return parent::getFooterActionsContentComponent()->key('footer-actions');
    }

    /** @return array<Action> */
    public function getFooterActions(): array
    {
        return [
            ...parent::getFooterActions(),
            Action::make('signOut')
                ->label('تسجيل الخروج')
                ->color('gray')
                ->link()
                ->action(function () {
                    Filament::auth()->logout();
                    session()->invalidate();
                    session()->regenerateToken();

                    return redirect()->to(Filament::getLoginUrl());
                }),
        ];
    }
}
