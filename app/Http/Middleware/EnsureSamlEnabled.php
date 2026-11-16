<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OneLogin\Saml2\Utils as SamlUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * In front of the SAML routes (decision D15):
 *
 * - 404 while SAML2_ENABLED is off;
 * - tells the SAML toolkit the public URL of this request as Laravel sees
 *   it (APP_URL host and port, HTTPS behind TRUSTED_PROXIES). On its own the
 *   toolkit reads $_SERVER, where nginx reports its internal port, and the
 *   signed Destination/Recipient check would then fail.
 */
class EnsureSamlEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('audit.saml.enabled'), 404);

        SamlUtils::setSelfProtocol($request->getScheme());
        SamlUtils::setSelfHost($request->getHost());
        // A string on purpose: the toolkit compares it with '80' / '443'.
        SamlUtils::setSelfPort((string) $request->getPort()); // @phpstan-ignore argument.type

        return $next($request);
    }
}
