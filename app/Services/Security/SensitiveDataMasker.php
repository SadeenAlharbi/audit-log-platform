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
 * A key matches when its words contain the words of a configured key, in
 * order: "password" matches "user_password" and "passwordConfirmation";
 * "card_number" matches "billingCardNumber"; "pin" matches "pin_code" but not
 * "shipping"; "token" matches "accessToken".
 */
class SensitiveDataMasker
{
    /** @var list<list<string>> */
    private array $needles;

    private string $replacement;

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
        $this->replacement = $replacement ?? (string) config('audit.masking.replacement', '********');
    }

    /** The instance used at ingestion: never_store keys, "[REDACTED]". */
    public static function forStorage(): self
    {
        return new self(
            array_values((array) config('audit.masking.never_store', [])),
            (string) config('audit.masking.redacted', '[REDACTED]'),
        );
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
            }
        }

        return $data;
    }

    public function isSensitive(string $key): bool
    {
        $words = self::words($key);
        $count = count($words);

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

    /**
     * "accessToken" / "access-token" / "ACCESS_TOKEN" -> ["access", "token"].
     *
     * @return list<string>
     */
    private static function words(string $key): array
    {
        $spaced = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key);
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : array_values($parts);
    }
}
