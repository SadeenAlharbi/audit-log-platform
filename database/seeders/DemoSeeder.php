<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Demo environment for reviewers (docs/product/product-overview.pdf §13):
 * four connected systems, about 800 logs over the last two weeks and one
 * account per role. Development only:
 *
 *   make demo      (php artisan db:seed --class=DemoSeeder)
 *
 * Every account uses DEMO_PASSWORD (default below). Platform-wide roles are
 * asked to set up two-factor authentication on first sign-in.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo!Passw0rd#2026';

    /**
     * One account per role: e-mail, name (shown as the role on the sign-in
     * page's demo list), role, project slug for project roles.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public const ACCOUNTS = [
        ['admin@demo.test', 'مدير المنصة', 'super_admin', null],
        ['auditor@demo.test', 'مدقق المنصة', 'platform_auditor', null],
        ['security@demo.test', 'مسؤول الأمن', 'security_officer', null],
        ['owner@demo.test', 'مالك مشروع الموارد البشرية', 'project_owner', 'hr'],
        ['viewer@demo.test', 'مشاهد مشروع شؤون الطلاب', 'project_viewer', 'sis'],
    ];

    private const PEOPLE = ['محمد القحطاني', 'سارة الدوسري', 'عبدالله المطيري', 'ريم الحربي', 'فهد العنزي', 'هيا السبيعي', 'تركي الرشيدي', 'لمى الزهراني'];

    /** @var array<string, array{0: string, 1: int, 2: int, 3: list<array{0: string, 1: string, 2: string, 3: array<string, string>|null, 4: array<string, string>|null}>}> */
    private const SYSTEMS = [
        'sis' => ['نظام شؤون الطلاب', 365, 260, [
            ['student.grade.updated', 'update', 'enrollment', ['grade' => 'B+'], ['grade' => 'A']],
            ['student.record.viewed', 'view', 'student', null, null],
            ['transcript.exported', 'export', 'student', null, null],
            ['user.login', 'login', 'user', null, null],
        ]],
        'hr' => ['نظام الموارد البشرية', 395, 140, [
            ['employee.salary.updated', 'update', 'employee', ['grade_level' => '7'], ['grade_level' => '8']],
            ['leave.approved', 'approve', 'leave_request', ['status' => 'pending'], ['status' => 'approved']],
            ['employee.bank_account.updated', 'update', 'employee', ['iban' => 'SA0380000000608010167519'], ['iban' => 'SA4420000001234567891234']],
            ['user.login', 'login', 'user', null, null],
        ]],
        'portal' => ['البوابة الإلكترونية', 180, 320, [
            ['user.login', 'login', 'user', null, null],
            ['user.login_failed', 'login', 'user', null, null],
            ['role.changed', 'update', 'user', ['role' => 'viewer'], ['role' => 'editor']],
            ['user.profile.updated', 'update', 'user', ['phone' => '0550000001'], ['phone' => '0550000002']],
        ]],
        'procurement' => ['نظام المشتريات', 365, 90, [
            ['purchase_order.created', 'create', 'purchase_order', null, ['amount' => '12500.00']],
            ['purchase_order.approved', 'approve', 'purchase_order', ['status' => 'pending'], ['status' => 'approved']],
            ['vendor.updated', 'update', 'vendor', ['city' => 'بريدة'], ['city' => 'عنيزة']],
            ['user.login', 'login', 'user', null, null],
        ]],
    ];

    /**
     * Rejected API requests for the "rejected attempts" page: one row per
     * attempt, plus summary rows that stand for many attempts of the same
     * minute (D21). Seeded once.
     *
     * @param  array<string, Project>  $projects
     */
    private function seedSecurityEvents(array $projects): void
    {
        if (SecurityEvent::query()->exists()) {
            return;
        }

        $samples = [
            // reason, project, ip, path, extra meta
            ['invalid_key', 'hr', '185.220.101.14', '/api/v1/logs', ['key_prefix' => 'alh_x9Qe']],
            ['invalid_key', 'hr', '185.220.101.14', '/api/v1/logs/batch', ['key_prefix' => 'alh_x9Qe']],
            ['ip_not_allowed', 'hr', '10.40.3.77', '/api/v1/logs', []],
            ['missing_signature', 'procurement', '10.20.8.15', '/api/v1/logs', []],
            ['invalid_signature', 'procurement', '10.20.8.15', '/api/v1/logs/batch', []],
            ['stale_timestamp', 'procurement', '10.20.8.16', '/api/v1/logs', []],
            ['key_revoked', 'portal', '10.30.1.9', '/api/v1/logs', []],
            ['key_expired', 'sis', '10.10.5.21', '/api/v1/logs', []],
            ['project_mismatch', 'sis', '10.10.5.21', '/api/v1/logs', ['project_header' => 'hr']],
            ['scope_denied', 'portal', '10.30.1.12', '/api/v1/logs', []],
            ['missing_credentials', null, '94.102.49.193', '/api/v1/logs', []],
            ['replayed_request', 'procurement', '10.20.8.15', '/api/v1/logs', []],
            ['payload_too_large', 'sis', '10.10.5.30', '/api/v1/logs/batch', []],
            ['https_required', 'portal', '10.30.1.9', '/api/v1/logs', []],
        ];

        $rows = [];
        foreach ($samples as $i => [$reason, $slug, $ip, $path, $meta]) {
            $project = $slug === null ? null : $projects[$slug] ?? null;
            $rows[] = [
                'project_id' => $project?->id,
                'ip' => $ip,
                'reason' => $reason,
                'request_meta' => json_encode(['method' => 'POST', 'path' => $path, 'user_agent' => 'Mozilla/5.0 (demo)', ...$meta]),
                'created_at' => CarbonImmutable::now('UTC')->subMinutes(15 + $i * 47)->format('Y-m-d H:i:s.v'),
            ];
        }

        // Floods over the per-minute sample: one summary row each (D21).
        foreach ([['missing_credentials', null, '94.102.49.193', 412, 95], ['rate_limited', 'portal', '10.30.1.12', 168, 300]] as [$reason, $slug, $ip, $count, $minutesAgo]) {
            $minute = CarbonImmutable::now('UTC')->subMinutes($minutesAgo)->startOfMinute();
            $rows[] = [
                'project_id' => $slug === null ? null : $projects[$slug]->id,
                'ip' => $ip,
                'reason' => $reason,
                'request_meta' => json_encode(['suppressed' => $count, 'window_start' => $minute->toIso8601String(), 'note' => 'attempts over the per-minute sample, counted not stored one by one']),
                'created_at' => $minute->addMinute()->subMillisecond()->format('Y-m-d H:i:s.v'),
            ];
        }

        DB::table('security_events')->insert($rows);
    }

    /** Backup, archive and restore-test history for the backups page. Seeded once. */
    private function seedBackupRuns(): void
    {
        if (BackupRun::query()->exists()) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        // type, status, days ago, size (bytes), location, minutes taken, error
        $runs = [
            ['full', 'succeeded', 1, 182_000_000, 'offsite:audit-backups/full/', 6, null],
            ['incremental', 'succeeded', 0, 6_400_000, 'offsite:audit-backups/incremental/', 1, null],
            ['binlog', 'succeeded', 0, 1_200_000, 'offsite:audit-backups/binlogs/', 1, null],
            ['logs_archive', 'succeeded', 0, 2_300_000, 'archive:logs/', 1, null],
            ['app_files', 'succeeded', 0, 450_000, 'offsite:audit-backups/app/', 1, null],
            ['restore_test', 'succeeded', 3, null, null, 9, null],
            ['incremental', 'failed', 2, null, null, 1, 'Upload to off-site storage timed out (demo)'],
        ];

        foreach ($runs as $i => [$type, $status, $daysAgo, $size, $location, $minutes, $error]) {
            $started = $now->subDays($daysAgo)->subHours($i + 1);
            BackupRun::query()->create([
                'type' => $type,
                'status' => $status,
                'size' => $size,
                'location' => $location,
                'checksum' => $location === null ? null : hash('sha256', "demo-{$type}-{$i}"),
                'meta' => ['demo' => true],
                'started_at' => $started,
                'finished_at' => $started->addMinutes($minutes),
                'error' => $error,
            ]);
        }
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'review'])) {
            throw new RuntimeException('DemoSeeder is for development and review environments only.');
        }

        $this->call(RolesAndPermissionsSeeder::class);
        mt_srand(7);
        $password = (string) config('audit.demo_password', self::PASSWORD);
        $keys = app(ApiKeyManager::class);
        $projects = [];

        foreach (self::SYSTEMS as $slug => [$name, $retention, $count, $events]) {
            $project = Project::query()->withoutGlobalScopes()->firstOrCreate(['slug' => $slug], ['name' => $name, 'retention_days' => $retention]);
            $projects[$slug] = $project;

            if (! ProjectApiKey::query()->withoutGlobalScopes()->where('project_id', $project->id)->exists()) {
                $keys->create($project, "{$slug}-writer", [ProjectApiKey::SCOPE_WRITE], 'production', true);
                $keys->create($project, "{$slug}-reader", [ProjectApiKey::SCOPE_READ], 'production', false);
            }

            // Logs are append-only, so a second run never re-seeds: it only tops
            // up the time since the previous run, at the same daily rate, so the
            // dashboard ("today", "last 24 hours") is not empty on a later day.
            // The previous run is the writer key's last use (set below), or the
            // newest log if that is later.
            $now = CarbonImmutable::now('UTC');
            $existing = AuditLog::query()->withoutGlobalScopes()->where('project_id', $project->id);
            $writer = DB::table('project_api_keys')->where('project_id', $project->id)->whereJsonContains('scopes', ProjectApiKey::SCOPE_WRITE);
            $seeded = (clone $existing)->count();
            $latest = max((string) (clone $existing)->max('occurred_at'), (string) (clone $writer)->max('last_used_at'));
            $from = $latest === '' ? $now->subDays(13) : CarbonImmutable::parse($latest, 'UTC');
            $span = max(0, $now->getTimestamp() - 120 - $from->getTimestamp());
            $add = $latest === '' ? $count : (int) round($count / 13 * $span / 86400);
            if ($add === 0 || $span < 3600) {
                continue;
            }

            $rows = [];
            for ($i = $seeded; $i < $seeded + $add; $i++) {
                [$event, $action, $subject, $old, $new] = $events[mt_rand(0, count($events) - 1)];
                $at = $now->subSeconds(120 + mt_rand(0, $span));
                $rows[] = [
                    'uid' => (string) Str::ulid(),
                    'project_id' => $project->id,
                    'external_id' => "demo-{$slug}-{$i}",
                    'event' => $event,
                    'action' => $action,
                    'severity' => match ($event) {
                        'role.changed' => 'critical',
                        'user.login_failed', 'employee.bank_account.updated' => 'warning',
                        default => 'info',
                    },
                    'actor_type' => 'user',
                    'actor_id' => (string) mt_rand(1000, 1999),
                    'actor_name' => self::PEOPLE[mt_rand(0, count(self::PEOPLE) - 1)],
                    'actor_ip' => '10.20.'.mt_rand(1, 40).'.'.mt_rand(2, 250),
                    'subject_type' => $subject,
                    'subject_id' => (string) mt_rand(10000, 99999),
                    'old_values' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
                    'new_values' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
                    'tags' => json_encode([$slug, 'demo']),
                    'occurred_at' => $at->format('Y-m-d H:i:s.v'),
                    'received_at' => $at->addSeconds(mt_rand(0, 3))->format('Y-m-d H:i:s.v'),
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('audit_logs')->insert($chunk);
            }

            // The writer key "sent" the logs of this run: "last received" on the
            // dashboard, and where the next run continues from.
            (clone $writer)->update(['last_used_at' => $now->format('Y-m-d H:i:s')]);
        }

        foreach (self::ACCOUNTS as [$email, $name, $role, $projectSlug]) {
            $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'is_active' => true]);
            if ($projectSlug === null) {
                $user->syncRoles([$role]);

                continue;
            }
            DB::table('project_user')->updateOrInsert(
                ['project_id' => $projects[$projectSlug]->id, 'user_id' => $user->id],
                ['role_id' => Role::findByName($role)->id, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $this->seedSecurityEvents($projects);
        $this->seedBackupRuns();

        $this->command->info('Demo ready: 4 systems, accounts *@demo.test (admin, auditor, security, owner, viewer), password: DEMO_PASSWORD or '.self::PASSWORD);
    }
}
