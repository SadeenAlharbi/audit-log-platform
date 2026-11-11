<?php

namespace App\Services\Security;

/**
 * Masks sensitive values in old_values / new_values / metadata (plan §7).
 *
 * Logs are stored as received; masking is applied whenever they are shown or
 * exported, unless the viewer holds logs.view_sensitive. API keys never see
 * sensitive values.
 */
class SensitiveDataMasker
{
    /** @var list<string> */
    private array $needles;

    private string $replacement;

    /**
     * @param  list<string>|null  $keys
     */
    public function __construct(?array $keys = null, ?string $replacement = null)
    {
        $this->needles = array_map(
            fn (string $k) => self::normalise($k),
            $keys ?? config('audit.masking.keys', []),
        );
        $this->replacement = $replacement ?? (string) config('audit.masking.replacement', '********');
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
        $normalised = self::normalise($key);

        foreach ($this->needles as $needle) {
            if ($needle !== '' && str_contains($normalised, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** "accessToken" / "access-token" / "ACCESS_TOKEN" -> "accesstoken". */
    private static function normalise(string $key): string
    {
        return strtolower(str_replace(['_', '-', ' ', '.'], '', $key));
    }
}
