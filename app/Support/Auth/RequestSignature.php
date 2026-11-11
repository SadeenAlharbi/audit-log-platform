<?php

namespace App\Support\Auth;

/**
 * The canonical string every client signs (documented in the OpenAPI spec
 * and implemented by company/audit-client):
 *
 *   {timestamp}\n{METHOD}\n{path?query}\n{raw body}
 */
final class RequestSignature
{
    public static function canonical(string $timestamp, string $method, string $uri, string $body): string
    {
        return $timestamp."\n".strtoupper($method)."\n".$uri."\n".$body;
    }

    public static function sign(string $secret, string $timestamp, string $method, string $uri, string $body): string
    {
        return hash_hmac('sha256', self::canonical($timestamp, $method, $uri, $body), $secret);
    }
}
