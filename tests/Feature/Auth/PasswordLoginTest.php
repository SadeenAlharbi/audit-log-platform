<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Models\PlatformAudit;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function passwordLogin(string $email, string $password = 'password'): Testable
{
    return Livewire::test(Login::class)
        ->set('data.email', $email)
        ->set('data.password', $password)
        ->call('authenticate');
}

it('asks for the 2FA code after a correct password, then signs in', function () {
    $user = platformUser('super_admin');
    $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();

    $page = passwordLogin($user->email)->assertHasNoErrors();
    $this->assertGuest();
    expect($page->get('userUndertakingMultiFactorAuthentication'))->toBeString();

    $page->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP'))
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Filament::auth()->id())->toBe($user->id);
});

it('keeps the generic message for a wrong password', function () {
    $user = platformUser('super_admin');

    passwordLogin($user->email, 'wrong-password')
        ->assertHasErrors(['data.email' => __('filament-panels::auth/pages/login.messages.failed')]);
});

it('explains a lockout or suspension only after the right password', function (array $state, string $expected) {
    $user = platformUser('super_admin');
    $user->forceFill($state)->save();

    $errors = passwordLogin($user->email)->errors();

    expect($errors->first('data.email'))->toContain($expected);
    $this->assertGuest();

    // A wrong password says nothing about the account.
    expect(passwordLogin($user->email, 'wrong-password')->errors()->first('data.email'))
        ->toBe(__('filament-panels::auth/pages/login.messages.failed'));
})->with([
    'locked' => [['locked_until' => now()->addMinutes(15)], 'مقفل مؤقتًا'],
    'suspended' => [['is_active' => false], 'موقوف'],
]);

it('unlocks an account from the console and records it', function () {
    $user = User::factory()->create(['email' => 'locked@example.com', 'locked_until' => now()->addMinutes(15), 'failed_login_attempts' => 0]);

    $this->artisan('audit:unlock-user', ['email' => 'LOCKED@example.com'])->assertSuccessful();

    expect($user->fresh()->isLocked())->toBeFalse()
        ->and(PlatformAudit::query()->where('action', 'user.unlocked')->exists())->toBeTrue();
    $this->artisan('audit:unlock-user', ['email' => 'nobody@example.com'])->assertFailed();
});

it('shows the profile page in sections and still needs the current password', function () {
    $user = platformUser('super_admin');
    $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->assertSee('البيانات الشخصية')
        ->assertSee('تغيير كلمة المرور')
        ->fillForm(['password' => 'N3w-Strong-Pass!word', 'passwordConfirmation' => 'N3w-Strong-Pass!word', 'currentPassword' => 'wrong'])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);
});

it('locks the account after five wrong 2FA codes following a correct password', function () {
    $user = platformUser('super_admin');
    $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();

    $page = passwordLogin($user->email)->assertHasNoErrors();
    foreach (range(1, 5) as $i) {
        // The sign-in form allows 5 submissions a minute.
        $this->travel(61)->seconds();
        $page->set('data.multiFactor.app.code', '000000')->call('authenticate')->assertHasErrors();
    }

    expect($user->fresh()->isLocked())->toBeTrue()
        ->and(PlatformAudit::query()->where('action', 'auth.account_locked')->value('changes'))->toMatchArray(['stage' => 'mfa']);

    // The right code no longer helps: the account is locked.
    $this->travel(61)->seconds();
    $page->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP'))
        ->call('authenticate');
    expect($page->errors()->first('data.email'))->toContain('مقفل مؤقتًا');
    $this->assertGuest();
});
