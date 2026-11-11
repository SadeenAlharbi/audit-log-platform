<?php

namespace App\Services\Export;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\PlatformAuditLogger;
use App\Services\Security\SensitiveDataMasker;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV / JSON export of audit logs (plan §8). The query passed in already
 * carries the dashboard filters and the ProjectAccessScope; values are
 * masked unless the user holds logs.view_sensitive on that project. Every
 * export is recorded in platform_audit.
 */
class AuditLogExporter
{
    public const MAX_ROWS = 100_000;

    public const COLUMNS = [
        'uid', 'project', 'external_id', 'event', 'action', 'severity',
        'actor_type', 'actor_id', 'actor_name', 'actor_ip', 'user_agent',
        'subject_type', 'subject_id', 'old_values', 'new_values', 'metadata', 'tags',
        'request_id', 'correlation_id', 'occurred_at', 'received_at',
    ];

    public function __construct(
        private SensitiveDataMasker $masker,
        private PlatformAuditLogger $audit,
    ) {}

    /**
     * @param  Builder<AuditLog>  $query
     */
    public function download(Builder $query, string $format, User $user): StreamedResponse
    {
        $format = $format === 'json' ? 'json' : 'csv';
        $total = (clone $query)->count();
        $filename = 'audit-logs-'.now()->utc()->format('Ymd-His').'.'.$format;

        $this->audit->record('logs.exported', 'audit_logs', [
            'format' => $format,
            'rows' => min($total, self::MAX_ROWS),
            'truncated' => $total > self::MAX_ROWS,
        ], $user);

        return response()->streamDownload(function () use ($query, $format, $user) {
            $out = fopen('php://output', 'w');
            $first = true;
            $sensitive = [];

            if ($format === 'csv') {
                fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
                fputcsv($out, self::COLUMNS, escape: '');
            } else {
                fwrite($out, "[\n");
            }

            $written = 0;
            $writeRow = function (AuditLog $log) use ($out, $format, $user, &$first, &$sensitive) {
                $reveal = $sensitive[$log->project_id] ??= $user->canInProject('logs.view_sensitive', $log->project_id);
                $row = $this->row($log, $reveal);

                if ($format === 'csv') {
                    fputcsv($out, array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $row), escape: '');
                } else {
                    fwrite($out, ($first ? '' : ",\n").json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $first = false;
                }
            };

            // Keyset pagination on (occurred_at, id): constant memory and
            // stable on a table that keeps receiving rows.
            $last = null;
            while ($written < self::MAX_ROWS) {
                $page = $query->clone()
                    ->with('project:id,slug')
                    ->reorder()
                    ->when($last !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                        ->where('occurred_at', '<', $last['occurred_at'])
                        ->orWhere(fn (Builder $e) => $e->where('occurred_at', $last['occurred_at'])->where('id', '<', $last['id']))))
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->limit(min(1000, self::MAX_ROWS - $written))
                    ->get();

                if ($page->isEmpty()) {
                    break;
                }

                $page->each($writeRow);
                $written += $page->count();

                $tail = $page->last();
                $last = ['occurred_at' => $tail->getRawOriginal('occurred_at'), 'id' => $tail->id];
                flush();
            }

            if ($format === 'json') {
                fwrite($out, "\n]\n");
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => $format === 'json' ? 'application/json' : 'text/csv; charset=UTF-8',
        ]);
    }

    /** @return array<string, mixed> */
    public function row(AuditLog $log, bool $revealSensitive): array
    {
        $mask = fn (?array $v) => $revealSensitive ? $v : $this->masker->mask($v);

        return [
            'uid' => $log->uid,
            'project' => $log->project?->slug,
            'external_id' => $log->external_id,
            'event' => $log->event,
            'action' => $log->action,
            'severity' => $log->severity,
            'actor_type' => $log->actor_type,
            'actor_id' => $log->actor_id,
            'actor_name' => $log->actor_name,
            'actor_ip' => $log->actor_ip,
            'user_agent' => $log->user_agent,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'old_values' => $mask($log->old_values),
            'new_values' => $mask($log->new_values),
            'metadata' => $mask($log->metadata),
            'tags' => $log->tags,
            'request_id' => $log->request_id,
            'correlation_id' => $log->correlation_id,
            'occurred_at' => $log->occurred_at->format('Y-m-d\TH:i:s.v\Z'),
            'received_at' => $log->received_at->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
