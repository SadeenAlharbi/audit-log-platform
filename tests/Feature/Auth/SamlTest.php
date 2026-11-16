<?php

use App\Filament\Auth\SetUpAppAuthenticationAction;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\SetUpTwoFactor;
use App\Listeners\HandleSamlSignIn;
use App\Models\PlatformAudit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Slides\Saml2\Models\Tenant;
use Tests\TestCase;

/*
 * SAML single sign-on (decision D15): real signed SAML responses posted to
 * the ACS endpoint, checked by the toolkit (signature, audience, destination,
 * time window), then the platform's own rules.
 */

const SAML_UUID = '6f1c2c5e-4b7a-4d2e-9a51-0c8e3f2b7d10';
const SAML_IDP = 'https://idp.example.test/realms/audit';

beforeEach(function () {
    seedRoles();
    config(['audit.saml.enabled' => true, 'audit.saml.tenant_uuid' => SAML_UUID]);

    // A throwaway IdP signing key, made for this test run.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'idp.example.test'], $key);
    openssl_x509_export(openssl_csr_sign($csr, null, $key, 2), $cert);
    openssl_pkey_export($key, $privateKey);
    $this->idpKey = $privateKey;
    $this->idpCert = $cert;

    Tenant::query()->forceCreate([
        'uuid' => SAML_UUID,
        'key' => 'default',
        'idp_entity_id' => SAML_IDP,
        'idp_login_url' => SAML_IDP.'/protocol/saml',
        'idp_logout_url' => SAML_IDP.'/protocol/saml',
        'idp_x509_cert' => $cert,
        'name_id_format' => 'emailAddress',
        'relay_state_url' => '/admin',
        'metadata' => [],
    ]);
});

/** A SAML Response for $email, its assertion signed with the test IdP key (or not). */
function samlResponse(TestCase $test, string $email, bool $signed = true, ?string $audience = null): string
{
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $later = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
    $before = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
    $acs = url('/saml2/'.SAML_UUID.'/acs');
    $audience ??= url('/saml2/'.SAML_UUID.'/metadata');
    $assertionId = '_a'.bin2hex(random_bytes(16));
    $idp = SAML_IDP;

    $assertion = <<<XML
<saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$assertionId}" Version="2.0" IssueInstant="{$now}"><saml:Issuer>{$idp}</saml:Issuer><saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">{$email}</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="{$later}" Recipient="{$acs}"/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="{$before}" NotOnOrAfter="{$later}"><saml:AudienceRestriction><saml:Audience>{$audience}</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AuthnStatement AuthnInstant="{$now}" SessionIndex="_s1"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement><saml:AttributeStatement><saml:Attribute Name="email"><saml:AttributeValue>{$email}</saml:AttributeValue></saml:Attribute></saml:AttributeStatement></saml:Assertion>
XML;
    if ($signed) {
        $assertion = signAssertion($assertion, $test->idpKey, $test->idpCert);
    }

    $response = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_r'.bin2hex(random_bytes(16)).'" Version="2.0" IssueInstant="'.$now.'" Destination="'.$acs.'"><saml:Issuer>'.SAML_IDP.'</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'.$assertion.'</samlp:Response>';

    return base64_encode($response);
}
/** Enveloped RSA-SHA256 signature placed right after the Issuer, as an IdP does. */
function signAssertion(string $xml, string $key, string $cert): string
{
    $doc = new DOMDocument;
    $doc->loadXML($xml);
    $root = $doc->documentElement;

    $dsig = new XMLSecurityDSig;
    $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
    $dsig->addReference($root, XMLSecurityDSig::SHA256, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N], ['id_name' => 'ID', 'overwrite' => false]);
    $signer = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
    $signer->loadKey($key);
    $dsig->sign($signer);
    $dsig->add509Cert($cert);
    $dsig->insertSignature($root, $root->getElementsByTagName('Issuer')->item(0)->nextSibling);

    return $doc->saveXML($root);
}

function postSaml(TestCase $test, string $email, bool $signed = true, ?string $audience = null)
{
    // The SAML toolkit reads PHP's superglobals, as under PHP-FPM.
    $_POST = ['SAMLResponse' => samlResponse($test, $email, $signed, $audience)];
    $_SERVER['REQUEST_URI'] = '/saml2/'.SAML_UUID.'/acs';

    try {
        return $test->post('/saml2/'.SAML_UUID.'/acs', $_POST);
    } finally {
        $_POST = [];
    }
}

/** Opens the "set up authenticator app" modal and returns the secret it shows. */
function mountTwoFactorSetup($page): string
{
    $page->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent('app.actions'));

    return decrypt($page->instance()->getMountedAction()->getArguments()['encrypted'])['secret'];
}

function samlAccount(string $role = 'project_viewer', bool $withMfa = false, bool $active = true): User
{
    $user = User::factory()->create(['email' => 'sara@company.test', 'is_active' => $active]);
    $user->assignRole($role);
    if ($withMfa) {
        $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    }

    return $user;
}

it('publishes the SP metadata, and nothing while SAML is off', function () {
    $this->get('/saml2/'.SAML_UUID.'/metadata')->assertOk()
        ->assertSee('entityID="'.url('/saml2/'.SAML_UUID.'/metadata').'"', false)
        ->assertSee(url('/saml2/'.SAML_UUID.'/acs'), false);

    config(['audit.saml.enabled' => false]);
    $this->get('/saml2/'.SAML_UUID.'/metadata')->assertNotFound();
});

it('shows the SAML button on the login page only when enabled', function () {
    $this->get('/admin/login')->assertOk()->assertSee('/saml2/'.SAML_UUID.'/login', false)->assertSee('الدخول عبر SAML');

    config(['audit.saml.enabled' => false]);
    $this->get('/admin/login')->assertOk()->assertDontSee('/saml2/'.SAML_UUID.'/login', false);
});

it('signs in an existing account without 2FA yet, then requires 2FA setup', function () {
    $user = samlAccount();

    postSaml($this, 'Sara@Company.test')->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user);
    expect(session('auth.via'))->toBe('saml')
        ->and($user->fresh()->requiresMultiFactorAuthentication())->toBeTrue()
        ->and(PlatformAudit::query()->where('action', 'auth.login')->latest('id')->value('changes'))->toMatchArray(['method' => 'saml']);
});

it('asks for the platform 2FA code after SAML before opening the session', function () {
    $user = samlAccount('platform_auditor', withMfa: true);

    postSaml($this, 'sara@company.test');

    $this->assertGuest();
    expect(session(HandleSamlSignIn::PENDING))->toBeString();

    // A wrong code is refused.
    Livewire::test(Login::class)
        ->assertSet('samlChallenge', true)
        ->set('data.multiFactor.app.code', '000000')
        ->call('authenticate')
        ->assertHasErrors();
    $this->assertGuest();

    // The right one signs in.
    $code = app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP');
    Livewire::test(Login::class)
        ->set('data.multiFactor.app.code', $code)
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Filament::auth()->id())->toBe($user->id)
        ->and(session(HandleSamlSignIn::PENDING))->toBeNull();
});

it('expires the 2FA step after five minutes', function () {
    samlAccount('platform_auditor', withMfa: true);
    postSaml($this, 'sara@company.test');

    $this->travel(HandleSamlSignIn::PENDING_SECONDS + 1)->seconds();

    Livewire::test(Login::class)->assertSet('samlChallenge', false);
    $this->assertGuest();
});

it('lets the user cancel the SAML 2FA step and use the password form', function () {
    samlAccount('platform_auditor', withMfa: true);
    postSaml($this, 'sara@company.test');

    Livewire::test(Login::class)
        ->assertSet('samlChallenge', true)
        ->call('cancelSaml')
        ->assertSet('samlChallenge', false)
        ->assertSet('userUndertakingMultiFactorAuthentication', null);

    expect(session(HandleSamlSignIn::PENDING))->toBeNull();
});

it('refuses an e-mail with no account, without creating one or storing the e-mail', function () {
    postSaml($this, 'stranger@company.test')->assertRedirect('/admin');

    $this->assertGuest();
    expect(User::query()->where('email', 'stranger@company.test')->exists())->toBeFalse()
        ->and(session(HandleSamlSignIn::DENIED))->toBeString();

    $audit = PlatformAudit::query()->where('action', 'auth.login_failed')->latest('id')->first();
    expect($audit->changes)->toHaveKey('identifier_hash')
        ->and(json_encode($audit->changes))->not->toContain('stranger');
});

it('refuses a deactivated or locked account', function () {
    $user = samlAccount(active: false);
    postSaml($this, 'sara@company.test');
    $this->assertGuest();

    $user->forceFill(['is_active' => true, 'locked_until' => now()->addMinutes(10)])->save();
    postSaml($this, 'sara@company.test');
    $this->assertGuest();
});

it('rejects an unsigned assertion or one meant for another service', function () {
    samlAccount();

    postSaml($this, 'sara@company.test', signed: false);
    $this->assertGuest();

    postSaml($this, 'sara@company.test', audience: 'https://other-app.example.test/metadata');
    $this->assertGuest();
});

it('configures the tenant from the IdP metadata', function () {
    Tenant::query()->delete();
    $certBody = preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->idpCert);
    Http::fake(['idp.example.test/*' => Http::response(<<<XML
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="https://idp.example.test/realms/audit">
  <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
    <md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>{$certBody}</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>
    <md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.example.test/realms/audit/protocol/saml"/>
    <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>
    <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.example.test/realms/audit/protocol/saml"/>
  </md:IDPSSODescriptor>
</md:EntityDescriptor>
XML)]);
    config(['audit.saml.idp.metadata_url' => 'https://idp.example.test/realms/audit/protocol/saml/descriptor']);

    $this->artisan('audit:saml-sync')->assertSuccessful();
    $this->artisan('audit:saml-sync')->assertSuccessful(); // idempotent

    $tenant = Tenant::query()->where('uuid', SAML_UUID)->sole();
    expect($tenant->idp_entity_id)->toBe(SAML_IDP)
        ->and($tenant->idp_login_url)->toBe(SAML_IDP.'/protocol/saml')
        ->and($tenant->idp_x509_cert)->toBe($certBody)
        ->and(PlatformAudit::query()->where('action', 'sso.configured')->count())->toBe(2);
});

it('sets up 2FA after SAML without asking for a platform password', function () {
    $user = samlAccount();
    postSaml($this, 'sara@company.test');

    $page = Livewire::test(SetUpTwoFactor::class)->assertSee('دخلت عبر SAML');
    $secret = mountTwoFactorSetup($page);

    $page->setActionData(['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($user->fresh()->app_authentication_secret)->toBe($secret);
});

it('still asks for the current password when 2FA is set up after a password login', function () {
    $user = samlAccount();
    $this->actingAs($user);

    $page = Livewire::test(SetUpTwoFactor::class);
    $secret = mountTwoFactorSetup($page);

    $page->setActionData(['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->callMountedAction()
        ->assertHasFormErrors(['password']);

    expect($user->fresh()->app_authentication_secret)->toBeNull();
});

it('offers a way out of the 2FA setup page', function () {
    samlAccount();
    postSaml($this, 'sara@company.test');
    $this->assertAuthenticated();

    Livewire::test(SetUpTwoFactor::class)
        ->callAction(TestAction::make('signOut')->schemaComponent('footer-actions'))
        ->assertRedirect(Filament::getLoginUrl());

    $this->assertGuest();
});

it('keeps the same QR secret when the setup window is closed and opened again', function () {
    $user = samlAccount();
    postSaml($this, 'sara@company.test');

    $first = mountTwoFactorSetup(Livewire::test(SetUpTwoFactor::class));
    $second = mountTwoFactorSetup(Livewire::test(SetUpTwoFactor::class));
    expect($second)->toBe($first);

    // Once set up, the pending secret is gone.
    $page = Livewire::test(SetUpTwoFactor::class);
    $secret = mountTwoFactorSetup($page);
    $page->setActionData(['code' => app(Google2FA::class)->getCurrentOtp($secret)])->callMountedAction();
    expect($user->fresh()->app_authentication_secret)->toBe($first)
        ->and(session(SetUpAppAuthenticationAction::PENDING_SECRET))->toBeNull();
});

it('warns on the login page when the server clock is off', function () {
    $this->get('/admin/login')->assertOk()
        ->assertSee('alh-clock-warning', false)
        ->assertSee('Date.now()', false);
});
