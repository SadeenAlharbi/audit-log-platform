<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectApiKey;
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

            if (AuditLog::query()->withoutGlobalScopes()->where('project_id', $project->id)->exists()) {
                continue; // idempotent: logs are append-only, never re-seeded
            }

            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                [$event, $action, $subject, $old, $new] = $events[mt_rand(0, count($events) - 1)];
                $at = CarbonImmutable::now('UTC')->subSeconds(mt_rand(120, 13 * 86400));
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

        $this->command->info('Demo ready: 4 systems, accounts *@demo.test (admin, auditor, security, owner, viewer), password: DEMO_PASSWORD or '.self::PASSWORD);
    }
}
