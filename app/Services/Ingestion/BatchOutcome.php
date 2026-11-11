<?php

namespace App\Services\Ingestion;

use App\Models\IngestionBatch;

final readonly class BatchOutcome
{
    /**
     * @param  array<int, IngestResult>  $results  index => result (valid items)
     * @param  array<int, array<string, list<string>>>  $rejected  index => errors
     */
    public function __construct(
        public IngestionBatch $batch,
        public array $results,
        public array $rejected,
    ) {}

    public function acceptedCount(): int
    {
        return count(array_filter($this->results, fn (IngestResult $r) => ! $r->isDuplicate()));
    }
}
