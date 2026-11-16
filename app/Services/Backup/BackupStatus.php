<?php

namespace App\Services\Backup;

use App\Models\BackupRun;
use App\Services\Archive\LogArchiver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Freshness of each backup type against the plan's targets
 * (RPO 1 hour, daily full, weekly restore test).
 */
class BackupStatus
{
    /**
     * @return array<string, array{label: string, value: string, ok: bool, last: ?Carbon}>
     */
    public function summary(): array
    {
        $cfg = config('audit.backup');

        return [
            'full' => $this->item('آخر نسخة كاملة', 'full', now()->subHours((int) $cfg['max_full_age_hours'])),
            'incremental' => $this->item('آخر نسخة تزايدية', 'incremental', now()->subMinutes((int) $cfg['max_incremental_age_minutes'])),
            'logs_archive' => $this->archiveItem(),
            'restore_test' => $this->item('آخر اختبار استعادة', 'restore_test', now()->subDays((int) $cfg['max_restore_test_age_days'])),
        ];
    }

    /**
     * @return list<string> human readable problems (empty = healthy)
     */
    public function problems(): array
    {
        return collect($this->summary())
            ->reject(fn (array $item) => $item['ok'])
            ->map(fn (array $item) => $item['label'].': '.$item['value'])
            ->values()
            ->all();
    }

    /**
     * The archiver records no run when there is nothing new, so an old
     * last run is only late if logs older than 26 hours are still waiting.
     *
     * @return array{label: string, value: string, ok: bool, last: ?Carbon}
     */
    private function archiveItem(): array
    {
        $item = $this->item('آخر أرشفة للسجلات', 'logs_archive', now()->subHours(26)); // daily run (D19) + margin
        if (! $item['ok'] && ! str_contains($item['value'], '(آخر محاولة فشلت)')) {
            $pending = DB::table('audit_logs')->where('id', '>', LogArchiver::watermark())
                ->where('created_at', '<', now()->utc()->subHours(26))->exists();
            if (! $pending) {
                $item['ok'] = true;
                $item['value'] = $item['last'] ? $item['last']->diffForHumans().' (لا جديد بعدها)' : 'لا توجد سجلات للأرشفة';
            }
        }

        return $item;
    }

    /** @return array{label: string, value: string, ok: bool, last: ?Carbon} */
    private function item(string $label, string $type, Carbon $threshold): array
    {
        $last = BackupRun::query()->where('type', $type)->where('status', 'succeeded')->latest('started_at')->first();
        $lastFailed = BackupRun::query()->where('type', $type)->where('status', 'failed')
            ->when($last, fn ($q) => $q->where('started_at', '>', $last->started_at))
            ->exists();

        $at = $last === null ? null : ($last->finished_at ?? $last->started_at);

        return [
            'label' => $label,
            'value' => $at ? $at->diffForHumans().($lastFailed ? ' (آخر محاولة فشلت)' : '') : 'لا يوجد',
            'ok' => $at !== null && $at->greaterThan($threshold) && ! $lastFailed,
            'last' => $at,
        ];
    }
}
