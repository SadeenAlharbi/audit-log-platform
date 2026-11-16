<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Audit\PlatformAuditLogger;
use Filament\Facades\Filament;
use Slides\Saml2\Events\SignedIn;
use Slides\Saml2\Saml2User;

/**
 * A valid, signed SAML assertion arrived (decision D15). Only an existing,
 * active, unlocked account with dashboard access is accepted, matched by
 * e-mail; nobody is created. The platform's own 2FA is still required:
 *
 * - 2FA set up: the account waits in the session ("saml.pending") and the
 *   login page asks for the code before signing in.
 * - 2FA not set up yet: signed in, then sent to the 2FA setup page
 *   (User::requiresMultiFactorAuthentication() is true for SAML sessions).
 */
class HandleSamlSignIn
{
    public const PENDING = 'saml.pending';

    public const DENIED = 'saml.denied';

    /** How long the 2FA step may take after the SAML sign-in. */
    public const PENDING_SECONDS = 300;

    public function __construct(private PlatformAuditLogger $audit) {}

    public function handle(SignedIn $event): void
    {
        $session = request()->session();
        $session->forget(self::PENDING);

        $email = self::email($event->getSaml2User());
        $user = $email === null ? null : User::query()->where('email', $email)->first();

        if ($user === null) {
            // Same as a failed password login for an unknown account: no
            // e-mail is stored, only a keyed hash for correlation.
            $this->audit->record('auth.login_failed', 'unknown_user', [
                'method' => 'saml',
                'identifier_hash' => substr(hash_hmac('sha256', (string) $email, (string) config('app.key')), 0, 16),
                'ip' => request()->ip(),
            ]);
            $session->put(self::DENIED, 'لا يوجد حساب في المنصة بهذا البريد. اطلب من مدير المنصة إضافتك.');

            return;
        }

        if (! $user->canAccessPanel(Filament::getPanel('admin'))) {
            $this->audit->record('auth.login_failed', $user, [
                'method' => 'saml',
                'reason' => ! $user->is_active ? 'inactive' : ($user->isLocked() ? 'locked' : 'no_access'),
                'ip' => request()->ip(),
            ], $user);
            $session->put(self::DENIED, 'هذا الحساب لا يملك صلاحية الدخول للوحة، أو أنه موقوف.');

            return;
        }

        if (filled($user->getAppAuthenticationSecret())) {
            $session->put(self::PENDING, encrypt([
                'identifier' => $user->getAuthIdentifier(),
                'userKey' => Filament::getUserScopedAuthIdentifier($user),
                'at' => now()->getTimestamp(),
            ]));

            return;
        }

        $session->put('auth.via', 'saml');
        Filament::auth()->login($user);
        $session->regenerate();
    }

    /** The e-mail from the configured attribute, else the NameID. */
    public static function email(Saml2User $user): ?string
    {
        $attribute = (string) config('audit.saml.email_attribute', 'email');
        $values = $user->getAttribute($attribute);
        $value = is_array($values) ? ($values[0] ?? null) : $values;
        $email = mb_strtolower(trim((string) ($value ?: $user->getUserId())));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
