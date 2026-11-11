<?php

namespace App\Jobs;

use App\Models\IngestionBatch;
use App\Services\Alerts\AlertNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes accepted logs to audit_logs. Retries with exponential backoff; a
 * job that still fails stays in failed_jobs so no log is lost silently.
 */
class StoreAuditLogs implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function __construct(
        public array $rows,
        public ?int $batchId = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(): void
    {
        DB::transaction(function () {
            // Retries are safe: a uid that is already stored is skipped.
            $existing = DB::table('audit_logs')
                ->whereIn('uid', array_column($this->rows, 'uid'))
                ->pluck('uid')
                ->flip();

            $pending = array_values(array_filter($this->rows, fn (array $row) => ! isset($existing[$row['uid']])));

            foreach (array_chunk($pending, 500) as $chunk) {
                DB::table('audit_logs')->insert($chunk);
            }
        });

        if ($this->batchId !== null) {
            $batch = IngestionBatch::query()->find($this->batchId);
            $batch?->forceFill([
                'status' => $batch->rejected_count > 0 ? 'partially_failed' : 'completed',
                'processed_at' => now(),
            ])->save();
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($this->batchId !== null) {
            IngestionBatch::query()->whereKey($this->batchId)->update(['status' => 'failed', 'processed_at' => now()]);
        }

        app(AlertNotifier::class)->send(
            key: 'ingest:failed',
            title: 'Audit logs could not be stored',
            message: count($this->rows).' log(s) failed after all retries and are kept in failed_jobs. Run `php artisan queue:retry all` after fixing the cause.',
            level: 'critical',
            context: ['error' => $e ? mb_substr($e->getMessage(), 0, 300) : null],
        );
    }
}
