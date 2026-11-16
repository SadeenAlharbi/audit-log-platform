<?php

namespace App\Filament\Pages\Auth;

use App\Listeners\HandleSamlSignIn;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Filament's login page plus SAML single sign-on (decision D15):
 *
 * - a "sign in with SAML" button under the password form, when enabled;
 * - after a SAML sign-in, the platform's own 2FA challenge (the same form
 *   Filament shows after a password), then the session is opened;
 * - the reason a SAML sign-in was refused (no account, inactive, locked).
 */
class Login extends BaseLogin
{
    /** The 2FA step was started by a SAML sign-in, not by a password. */
    #[Locked]
    public bool $samlChallenge = false;

    public function mount(): void
    {
        parent::mount();

        if ($message = session()->pull(HandleSamlSignIn::DENIED)) {
            Notification::make()->title('تعذّر الدخول الموحد')->body((string) $message)->danger()->send();
        }

        $pending = $this->pendingSamlUser();
        if ($pending === null) {
            return;
        }

        $this->userUndertakingMultiFactorAuthentication = (string) session(HandleSamlSignIn::PENDING);
        $user = $this->getUserUndertakingMultiFactorAuthentication();
        $provider = $user ? $this->getMultiFactorChallenge()->getFirstEnabledProvider($user) : null;

        if ($user === null || $provider === null) {
            $this->cancelSamlChallenge();

            return;
        }

        $this->samlChallenge = true;
        $this->getMultiFactorChallenge()->beforeChallenge($user, $provider);
        $this->multiFactorChallengeForm->fill();
    }

    public function authenticate(): ?LoginResponse
    {
        if (! $this->samlChallenge) {
            return parent::authenticate();
        }

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        // The encrypted id must still be the one this session's SAML
        // sign-in produced, within its time limit.
        $user = $this->pendingSamlUser() !== null
            && hash_equals((string) session(HandleSamlSignIn::PENDING), (string) $this->userUndertakingMultiFactorAuthentication)
            ? $this->getUserUndertakingMultiFactorAuthentication()
            : null;

        if ($user === null || ! $this->isUserAllowedToAccessPanel($user)) {
            $this->cancelSamlChallenge();
            Notification::make()->title('انتهت مهلة الدخول الموحد')->body('ابدأ الدخول من جديد.')->danger()->send();

            return null;
        }

        if ($this->isMultiFactorChallengeRateLimited($user)) {
            return null;
        }

        $this->cacheSchema('multiFactorChallengeForm', null);
        $this->multiFactorChallengeForm->validate();

        session()->forget(HandleSamlSignIn::PENDING);
        session()->put('auth.via', 'saml');
        Filament::auth()->login($user);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    /** The single sign-on button sits under the password form, full width. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
            $this->getFormContentComponent(),
            Actions::make([
                Action::make('saml')
                    ->label((string) config('audit.saml.button_label'))
                    ->color('gray')
                    ->outlined()
                    ->url('/saml2/'.config('audit.saml.tenant_uuid').'/login'),
            ])
                ->fullWidth()
                ->key('sso-actions')
                ->visible(fn (): bool => blank($this->userUndertakingMultiFactorAuthentication)
                    && config('audit.saml.enabled') && filled(config('audit.saml.tenant_uuid'))),
            $this->getMultiFactorChallengeFormContentComponent(),
            RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
        ]);
    }

    /** @return array<int, Action> */
    protected function getMultiFactorChallengeFormActions(): array
    {
        $actions = parent::getMultiFactorChallengeFormActions();

        if ($this->samlChallenge) {
            $actions[] = Action::make('cancelSaml')
                ->label('إلغاء والدخول بكلمة المرور')
                ->link()
                ->color('gray')
                ->action(fn () => $this->cancelSaml());
        }

        return $actions;
    }

    /** @return array{identifier: mixed, userKey: string, at: int}|null */
    private function pendingSamlUser(): ?array
    {
        $payload = session(HandleSamlSignIn::PENDING);
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $data = decrypt($payload);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || ! is_int($data['at'] ?? null) || ! is_string($data['userKey'] ?? null)
            || now()->getTimestamp() - $data['at'] > HandleSamlSignIn::PENDING_SECONDS) {
            return null;
        }

        return $data;
    }

    /** "Cancel and use the password": back to the e-mail and password form. */
    public function cancelSaml(): void
    {
        $this->cancelSamlChallenge();
    }

    private function cancelSamlChallenge(): void
    {
        session()->forget(HandleSamlSignIn::PENDING);
        $this->samlChallenge = false;
        $this->userUndertakingMultiFactorAuthentication = null;
    }
}
