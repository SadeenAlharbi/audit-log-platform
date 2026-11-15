<?php

namespace App\Services\Ingestion;

use App\Jobs\StoreAuditLogs;
use App\Models\IngestionBatch;
use App\Models\ProjectApiKey;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Accepts validated logs (plan §6, decisions D2 + D4):
 *
 *  1. every external_id is reserved synchronously in ingestion_keys, so a
 *     duplicate is known while the client is still waiting;
 *  2. a ULID is generated for each new log and returned with 202;
 *  3. the rows are queued and written to audit_logs by StoreAuditLogs.
 */
class IngestionService
{
    /**
     * @param  array<string, mixed>  $data  validated payload
     */
    public function ingestOne(ProjectApiKey $key, array $data): IngestResult
    {
        $receivedAt = CarbonImmutable::now('UTC');
        [$result] = $this->reserve($key, [$data], $receivedAt);

        if (! $result->isDuplicate()) {
            $this->dispatch([LogNormalizer::toRow($data, $key, $result->uid, $receivedAt)], null);
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $valid  index => validated payload
     * @param  array<int, array<string, list<string>>>  $rejected  index => errors
     */
    public function ingestBatch(ProjectApiKey $key, array $valid, array $rejected, int $total): BatchOutcome
    {
        $receivedAt = CarbonImmutable::now('UTC');
        $results = $valid === [] ? [] : array_combine(array_keys($valid), $this->reserve($key, array_values($valid), $receivedAt));

        $accepted = array_filter($results, fn (IngestResult $r) => ! $r->isDuplicate());
        $duplicates = array_filter($results, fn (IngestResult $r) => $r->isDuplicate());

        $batch = IngestionBatch::query()->create([
            'project_id' => $key->project_id,
            'api_key_id' => $key->id,
            'count' => $total,
            'accepted_count' => count($accepted),
            'duplicate_count' => count($duplicates),
            'rejected_count' => count($rejected),
            'status' => $accepted === [] ? ($rejected === [] ? 'completed' : 'failed') : 'queued',
            'errors' => $rejected === [] ? null : array_map(fn (int $i, array $e) => ['index' => $i, 'errors' => $e], array_keys($rejected), $rejected),
            'processed_at' => $accepted === [] ? now() : null,
        ]);

        if ($accepted !== []) {
            $rows = [];
            foreach ($accepted as $index => $result) {
                $rows[] = LogNormalizer::toRow($valid[$index], $key, $result->uid, $receivedAt, $batch->id);
            }
            $this->dispatch($rows, $batch->id);
        }

        return new BatchOutcome($batch, $results, $rejected);
    }

    /**
     * Reserve idempotency keys. Items without external_id are always new.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<IngestResult>
     */
    private function reserve(ProjectApiKey $key, array $items, CarbonImmutable $receivedAt): array
    {
        $uids = array_map(fn () => (string) Str::ulid(), $items);

        $reservations = [];
        foreach ($items as $i => $item) {
            $externalId = isset($item['external_id']) && $item['external_id'] !== '' ? (string) $item['external_id'] : null;
            if ($externalId !== null) {
                $reservations[$i] = [
                    'project_id' => $key->project_id,
                    'external_id' => $externalId,
                    'log_uid' => $uids[$i],
                    'occurred_at' => CarbonImmutable::parse((string) $item['occurred_at'])->utc()->format(LogNormalizer::DATETIME_FORMAT),
                    'created_at' => $receivedAt->format(LogNormalizer::DATETIME_FORMAT),
                ];
            }
        }

        $owners = [];
        if ($reservations !== []) {
            // INSERT IGNORE: rows whose (project_id, external_id) already exist
            // are skipped; the follow-up SELECT tells us who owns each key.
            foreach (array_chunk($reservations, 500) as $chunk) {
                DB::table('ingestion_keys')->insertOrIgnore($chunk);
            }

            $externalIds = array_values(array_unique(array_column($reservations, 'external_id')));
            foreach (array_chunk($externalIds, 500) as $chunk) {
                DB::table('ingestion_keys')
                    ->where('project_id', $key->project_id)
                    ->whereIn('external_id', $chunk)
                    ->pluck('log_uid', 'external_id')
                    ->each(function (string $uid, string $externalId) use (&$owners) {
                        $owners[$externalId] = $uid;
                    });
            }
        }

        $results = [];
        $claimed = [];
        foreach ($items as $i => $item) {
            if (! isset($reservations[$i])) {
                $results[] = new IngestResult(IngestResult::ACCEPTED, $uids[$i]);

                continue;
            }

            $externalId = $reservations[$i]['external_id'];
            $owner = $owners[$externalId] ?? null;

            // New only if our uid won the reservation and this is the first
            // occurrence of the external_id inside this request.
            if ($owner === $uids[$i] && ! isset($claimed[$externalId])) {
                $claimed[$externalId] = true;
                $results[] = new IngestResult(IngestResult::ACCEPTED, $uids[$i], $externalId);
            } else {
                $results[] = new IngestResult(IngestResult::DUPLICATE, $owner ?? $uids[$i], $externalId);
            }
        }

        return $results;
    }

    /**
     * The idempotency key is already reserved (and ingestion_keys is
     * append-only), so if the queue push fails (Redis down) the logs are
     * stored synchronously instead: otherwise the client's retry would be
     * answered "duplicate" for a log that was never stored. Only if that
     * fails too does the client get a 5xx.
     *
     * @param  list<array<string, scalar|null>>  $rows
     */
    private function dispatch(array $rows, ?int $batchId): void
    {
        $job = (new StoreAuditLogs($rows, $batchId))->onQueue((string) config('audit.api.queue', 'ingest'));

        try {
            // Explicit dispatch (PendingDispatch only pushes in its
            // destructor) so a failure is caught right here.
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            report($e);
            app()->call([$job, 'handle']);
        }
    }
}
