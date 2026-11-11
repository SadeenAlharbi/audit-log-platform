<?php

namespace App\Services\ApiKeys;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Allowed-IP rules: single addresses or CIDR ranges, IPv4 and IPv6.
 */
final class IpMatcher
{
    /**
     * @param  list<string>|null  $rules
     */
    public static function allows(?array $rules, ?string $ip): bool
    {
        if ($rules === null || $rules === []) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $rules);
    }

    public static function isValidRule(string $rule): bool
    {
        [$address, $mask] = array_pad(explode('/', $rule, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($mask === null) {
            return true;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        return ctype_digit($mask) && (int) $mask >= 0 && (int) $mask <= $max;
    }
}
