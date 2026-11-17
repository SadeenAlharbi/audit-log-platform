<?php

namespace App\Filament\Widgets;

use App\Filament\Support\PlatformsCode;
use App\Models\AuditLog;
use App\Support\Time\DisplayTime;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class LogVolumeChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'حجم السجلات اليومي (آخر 14 يومًا)';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $user = auth()->user();

        // Days are the users' local days (DisplayTime), not UTC days.
        $offset = DisplayTime::offsetMinutes();

        /** @var list<array{day: string, severity: string, total: int}> $rows */
        $rows = Cache::remember('dashboard:volume:'.$user->id, 300, fn () => AuditLog::query()
            ->where('occurred_at', '>=', DisplayTime::startOfDayUtc(13))
            ->selectRaw("DATE(DATE_ADD(occurred_at, INTERVAL {$offset} MINUTE)) AS day, severity, COUNT(*) AS total")
            ->groupBy('day', 'severity')
            ->toBase()
            ->get()
            ->map(fn (object $r) => ['day' => (string) $r->day, 'severity' => (string) $r->severity, 'total' => (int) $r->total])
            ->all());

        $days = collect(range(13, 0))->map(fn ($i) => DisplayTime::now()->subDays($i)->toDateString());
        $series = fn (string $severity) => $days->map(fn ($d) => collect($rows)->where('day', $d)->where('severity', $severity)->sum('total'))->all();

        return [
            'datasets' => [
                ['label' => 'معلومة', 'data' => $series('info'), 'backgroundColor' => PlatformsCode::PRIMARY[600], 'stack' => 's'],
                ['label' => 'تحذير', 'data' => $series('warning'), 'backgroundColor' => PlatformsCode::WARNING[500], 'stack' => 's'],
                ['label' => 'حرج', 'data' => $series('critical'), 'backgroundColor' => PlatformsCode::DANGER[600], 'stack' => 's'],
            ],
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('m-d'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true, 'beginAtZero' => true]]];
    }
}
