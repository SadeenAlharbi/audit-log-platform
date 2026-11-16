<?php

use App\Http\Middleware\EnsureSamlEnabled;
use Illuminate\Support\Facades\Route;
use Slides\Saml2\Http\Controllers\Saml2Controller;
use Slides\Saml2\Http\Middleware\ResolveTenant;

Route::redirect('/', '/admin');

// Integration docs (plan §15): rendered from docs/openapi.yaml.
Route::view('/docs/api', 'docs.api')->name('api.docs');

// SAML single sign-on (decision D15, scaler-tech/laravel-saml2). Defined
// here rather than by the package so the session starts before the tenant
// is resolved; the IdP's POST to /acs is exempt from CSRF (bootstrap/app.php).
// Answers 404 while SAML2_ENABLED is off.
Route::prefix('saml2')
    ->middleware([EnsureSamlEnabled::class, 'web', ResolveTenant::class, 'throttle:30,1'])
    ->controller(Saml2Controller::class)
    ->group(function () {
        Route::get('/{uuid}/login', 'login')->name('saml.login');
        Route::get('/{uuid}/logout', 'logout')->name('saml.logout');
        Route::get('/{uuid}/metadata', 'metadata')->name('saml.metadata');
        Route::post('/{uuid}/acs', 'acs')->name('saml.acs');
        Route::match(['GET', 'POST'], '/{uuid}/sls', 'sls')->name('saml.sls');
    });
