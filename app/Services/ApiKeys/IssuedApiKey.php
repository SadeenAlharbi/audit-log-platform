<?php

namespace App\Services\ApiKeys;

use App\Models\ProjectApiKey;

/**
 * Result of creating or rotating a key. The plain key and HMAC secret exist
 * only here and must be shown to the user once, then discarded.
 */
final readonly class IssuedApiKey
{
    public function __construct(
        public ProjectApiKey $key,
        public string $plainKey,
        public ?string $plainSecret,
    ) {}
}
