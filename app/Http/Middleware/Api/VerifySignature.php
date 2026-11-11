<?php

namespace App\Http\Middleware\Api;

use App\Http\Responses\ApiError;
use App\Models\ProjectApiKey;
use App\Services\Security\SecurityEventRecorder;
use App\Support\Auth\RequestSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan §7: HMAC-SHA256 request signing for keys that have a secret.
 *
 *   X-Timestamp: unix seconds
 *   X-Signature: hex(HMAC-SHA256(secret, canonical string))
 *
 * Requests outside the tolerance window, or replayed with an already
 * used signature, are rejected.
 */
class VerifySignature
{
    public function __construct(private SecurityEventRecorder $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var ProjectApiKey $key */
        $key = $request->attributes->get('api_key');
        $secret = $key->hmac_secret;

        if ($secret === null || $secret === '') {
            return $next($request);
        }

        $timestamp = (string) $request->header('X-Timestamp', '');
        $signature = strtolower((string) $request->header('X-Signature', ''));
        $signature = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;

        if ($timestamp === '' || $signature === '') {
            return $this->reject($request, 'missing_signature', $key);
        }

        $tolerance = (int) config('audit.api.signature_tolerance_seconds', 300);

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            return $this->reject($request, 'stale_timestamp', $key);
        }

        $expected = RequestSignature::sign(
            $secret,
            $timestamp,
            $request->method(),
            $request->getRequestUri(),
            (string) $request->getContent(),
        );

        if (! hash_equals($expected, $signature)) {
            return $this->reject($request, 'invalid_signature', $key);
        }

        // One signature, one use.
        if (! Cache::add("api:signature:{$key->id}:{$signature}", true, $tolerance * 2)) {
            return $this->reject($request, 'replayed_request', $key);
        }

        return $next($request);
    }

    private function reject(Request $request, string $reason, ProjectApiKey $key): Response
    {
        $this->security->record($request, $reason, $key->project, $key);

        return ApiError::make('unauthorized', 'Invalid or missing request signature.', 401);
    }
}
