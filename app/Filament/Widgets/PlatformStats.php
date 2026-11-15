<?php

namespace App\Filament\Widgets;

use App\Models\AuditLog;
use App\Models\IngestionBatch;
use App\Models\Project;
use App\Models\SecurityEvent;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard KPIs (plan §8). Counts go through the global scope, so a
 * project member only sees their projects.
 */
class PlatformStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    /** Five cards on one row on wide screens (no half-empty second row). */
    protected function getColumns(): int|array|null
    {
        return ['md' => 3, 'xl' => 5];
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $key = 'dashboard:stats:'.$user->id;

        $data = Cache::remember($key, 60, function () use ($user) {
            $today = now()->utc()->startOfDay();

            return [
                'today' => AuditLog::query()->where('occurred_at', '>=', $today)->count(),
                'yesterday' => AuditLog::query()->whereBetween('occurred_at', [$today->copy()->subDay(), $today])->count(),
                'critical' => AuditLog::query()->where('occurred_at', '>=', $today)->where('severity', 'critical')->count(),
                'projects' => Project::query()->whereNull('archived_at')->where('is_active', true)->count(),
                'rejected' => $user->canGlobally('security_events.view')
                    ? SecurityEvent::query()->where('created_at', '>=', now()->subDay())->count()
                    : null,
                'failed_batches' => IngestionBatch::query()->where('created_at', '>=', now()->subDay())->where('status', 'failed')->count(),
            ];
        });

        $trend = $data['yesterday'] > 0 ? round(($data['today'] - $data['yesterday']) / $data['yesterday'] * 100) : null;

        return array_values(array_filter([
            Stat::make('سجلات اليوم', number_format($data['today']))
                ->description($trend === null ? 'منذ منتصف الليل (UTC)' : ($trend >= 0 ? "+{$trend}% عن أمس" : "{$trend}% عن أمس"))
                ->color('primary'),
            Stat::make('أحداث حرجة اليوم', number_format($data['critical']))
                ->color($data['critical'] > 0 ? 'danger' : 'success'),
            Stat::make('مشاريع نشطة', number_format($data['projects'])),
            $data['rejected'] === null ? null : Stat::make('محاولات مرفوضة (24 ساعة)', number_format($data['rejected']))
                ->color($data['rejected'] > 0 ? 'warning' : 'success'),
            Stat::make('دفعات فاشلة (24 ساعة)', number_format($data['failed_batches']))
                ->color($data['failed_batches'] > 0 ? 'danger' : 'success'),
        ]));
    }
}
