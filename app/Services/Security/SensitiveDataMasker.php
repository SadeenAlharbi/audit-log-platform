<?php

namespace App\Services\Security;

/**
 * Masks sensitive values in old_values / new_values / metadata (plan §7, D8).
 *
 * Two uses:
 *  - forStorage(): applied at ingestion with the "never_store" list, so
 *    credentials and payment secrets are replaced before anything is written.
 *  - the default instance: applied whenever logs are shown, exported or
 *    returned by the API, unless the viewer holds logs.view_sensitive.
 *
 * A key matches when
 *  - its words contain the words of a configured key, in order: "password"
 *    matches "user_password", "DBPassword", "password2"; "card_number"
 *    matches "billingCardNumber"; "pin" matches "PINCode" but not "shipping";
 *  - or, for configured keys of 5+ letters, the key without separators ends
 *    with it: "dbpassword", "mysecret", "apitoken" (but not "secretary").
 * The storage redactor also replaces values that are credentials whatever
 * the key: "Bearer …"/"Basic …" headers, JWTs and platform API keys.
 */
class SensitiveDataMasker
{
    /** @var list<list<string>> */
    private array $needles;

    /** @var list<string> compacted needles of 5+ letters, matched as suffix */
    private array $suffixes;

    private string $replacement;

    private bool $redactSecretValues = false;

    /**
     * @param  list<string>|null  $keys
     */
    public function __construct(?array $keys = null, ?string $replacement = null)
    {
        $keys ??= array_merge(
            (array) config('audit.masking.keys', []),
            (array) config('audit.masking.never_store', []),
        );

        $this->needles = array_values(array_filter(array_map(
            fn (string $k) => self::words($k),
            array_values(array_unique($keys)),
        )));
        $this->suffixes = array_values(array_filter(
            array_map(fn (array $words) => implode('', $words), $this->needles),
            fn (string $compact) => strlen($compact) >= 5,
        ));
        $this->replacement = $replacement ?? (string) config('audit.masking.replacement', '********');
    }

    /** The instance used at ingestion: never_store keys, "[REDACTED]". */
    public static function forStorage(): self
    {
        $redactor = new self(
            array_values((array) config('audit.masking.never_store', [])),
            (string) config('audit.masking.redacted', '[REDACTED]'),
        );
        $redactor->redactSecretValues = true;

        return $redactor;
    }

    /**
     * @param  array<mixed>|null  $data
     * @return array<mixed>|null
     */
    public function mask(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = $value === null ? null : $this->replacement;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->mask($value);
            } elseif ($this->redactSecretValues && is_string($value) && self::looksLikeSecret($value)) {
                $data[$key] = $this->replacement;
            }
        }

        return $data;
    }

    public function isSensitive(string $key): bool
    {
        $words = self::words($key);
        $count = count($words);
        $compact = implode('', $words);

        foreach ($this->suffixes as $suffix) {
            if (str_ends_with($compact, $suffix)) {
                return true;
            }
        }

        foreach ($this->needles as $needle) {
            $length = count($needle);

            for ($i = 0; $i + $length <= $count; $i++) {
                if (array_slice($words, $i, $length) === $needle) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Credentials recognisable by their value, whatever the key. */
    public static function looksLikeSecret(string $value): bool
    {
        return (bool) preg_match('/^\s*(Bearer|Basic|Digest)\s+\S{8,}/i', $value)
            || (bool) preg_match('/^eyJ[\w-]{8,}\.[\w-]{8,}\.[\w-]+$/', $value)          // JWT
            || (bool) preg_match('/^alh_[A-Za-z0-9]{40,}$/', $value);                     // platform API key
    }

    /**
     * "accessToken" / "access-token" / "ACCESS_TOKEN" -> ["access", "token"];
     * "APIToken" -> ["api", "token"]; "password2" -> ["password", "2"].
     *
     * @return list<string>
     */
    private static function words(string $key): array
    {
        $spaced = (string) preg_replace(
            ['/(?<=[a-z0-9])(?=[A-Z])/', '/(?<=[A-Z])(?=[A-Z][a-z])/', '/(?<=[A-Za-z])(?=[0-9])|(?<=[0-9])(?=[A-Za-z])/'],
            ' ',
            $key,
        );
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }
}
