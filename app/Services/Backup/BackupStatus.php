<?php

namespace App\Services\Backup;

use App\Models\BackupRun;
use Illuminate\Support\Carbon;

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
            'logs_archive' => $this->item('آخر أرشفة للسجلات', 'logs_archive', now()->subHours(2)),
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
