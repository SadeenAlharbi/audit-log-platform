<?php

namespace App\Services\Ingestion;

final readonly class IngestResult
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    public function __construct(
        public string $status,
        public string $uid,
        public ?string $externalId = null,
    ) {}

    public function isDuplicate(): bool
    {
        return $this->status === self::DUPLICATE;
    }
}
