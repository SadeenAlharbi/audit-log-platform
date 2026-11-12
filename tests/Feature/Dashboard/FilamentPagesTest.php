<?php

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\BackupRuns\Pages\ListBackupRuns;
use App\Filament\Resources\PlatformAudits\Pages\ListPlatformAudits;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\ApiKeysRelationManager;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\SecurityEvents\Pages\ListSecurityEvents;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\PlatformAudit;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\User;
use App\Services\Export\AuditLogExporter;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = platformUser('super_admin');
    // MFA is mandatory for super admins; pretend it is set up.
    $this->admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
});

it('renders every page for a super admin', function () {
    $project = Project::factory()->create();
    $log = makeAuditLog($project);
    $this->actingAs($this->admin);

    foreach ([
        '/admin', '/admin/projects', '/admin/projects/create', "/admin/projects/{$project->id}",
        '/admin/audit-logs', "/admin/audit-logs/{$log->uid}", '/admin/security-events',
        '/admin/backup-runs', '/admin/users', '/admin/roles', '/admin/platform-audit',
    ] as $url) {
        $this->get($url)->assertOk();
    }
});

it('forces a super admin without 2FA to set it up', function () {
    $this->admin->forceFill(['app_authentication_secret' => null])->save();

    $this->actingAs($this->admin)->get('/admin/projects')->assertRedirect();
});

it('does not force 2FA on a project viewer', function () {
    $viewer = projectUser(Project::factory()->create(), 'project_viewer');

    $this->actingAs($viewer)->get('/admin/audit-logs')->assertOk();
});

it('has no create, edit or delete pages for audit logs', function () {
    expect(AuditLogResource::getPages())->toHaveKeys(['index', 'view'])->not->toHaveKeys(['create', 'edit']);

    $this->actingAs($this->admin)->get('/admin/audit-logs/create')->assertNotFound();
});

it('shows a project viewer only their project\'s logs', function () {
    [$a, $b] = Project::factory()->count(2)->create();
    $logA = makeAuditLog($a);
    $logB = makeAuditLog($b);

    $this->actingAs(projectUser($a, 'project_viewer'));

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$logA])
        ->assertCanNotSeeTableRecords([$logB]);

    $this->get("/admin/audit-logs/{$logB->uid}")->assertNotFound();
});

it('forbids admin areas to project users', function () {
    $this->actingAs(projectUser(Project::factory()->create(), 'project_owner'));

    $this->get('/admin/users')->assertForbidden();
    $this->get('/admin/roles')->assertForbidden();
    $this->get('/admin/security-events')->assertForbidden();
    $this->get('/admin/backup-runs')->assertForbidden();
    $this->get('/admin/platform-audit')->assertForbidden();
    $this->get('/admin/projects/create')->assertForbidden();
});

it('creates a project with a first API key shown once', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateProject::class)
        ->fillForm(['name' => 'ERP System', 'slug' => 'erp-system', 'retention_days' => 180])
        ->call('create')
        ->assertHasNoFormErrors();

    $project = Project::query()->where('slug', 'erp-system')->firstOrFail();
    $key = ProjectApiKey::query()->where('project_id', $project->id)->firstOrFail();

    expect($project->retention_days)->toBe(180)
        ->and($key->scopes)->toBe(['logs:write'])
        ->and($key->hmac_secret)->not->toBeNull()
        ->and(session('issued_api_key.key'))->toStartWith('alh_')
        ->and(ProjectApiKey::hashKey(session('issued_api_key.key')))->toBe($key->key_hash);

    expect(PlatformAudit::query()->where('action', 'api_key.created')->exists())->toBeTrue()
        ->and(PlatformAudit::query()->where('action', 'project.created')->exists())->toBeTrue();
});

it('creates, rotates and revokes keys from the project page', function () {
    $project = Project::factory()->create();
    $this->actingAs($this->admin);

    $rm = Livewire::test(ApiKeysRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class]);

    $rm->callAction(TestAction::make('createKey')->table(), [
        'name' => 'staging', 'environment' => 'staging', 'scope' => 'write', 'signed' => false,
    ])->assertHasNoActionErrors();

    $key = ProjectApiKey::query()->where('project_id', $project->id)->firstOrFail();
    expect($key->environment)->toBe('staging')->and($key->hmac_secret)->toBeNull();

    // The new key is shown once in a modal; the user closes it.
    $rm->assertActionMounted('showIssuedKey')->call('unmountAction');

    $rm->callAction(TestAction::make('rotate')->table($key));
    $rm->assertActionMounted('showIssuedKey')->call('unmountAction');
    expect(ProjectApiKey::query()->where('project_id', $project->id)->count())->toBe(2)
        ->and($key->fresh()->expires_at->lessThanOrEqualTo(now()->addDay()))->toBeTrue();

    $rm->callAction(TestAction::make('revoke')->table($key), ['reason' => 'leaked']);
    expect($key->fresh()->revoked_at)->not->toBeNull();
});

it('refuses to combine read and write scopes without confirmation', function () {
    $project = Project::factory()->create();
    $this->actingAs($this->admin);

    Livewire::test(ApiKeysRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->callAction(TestAction::make('createKey')->table(), [
            'name' => 'both', 'environment' => 'production', 'scope' => 'both', 'confirm_combined' => false, 'signed' => true,
        ])
        ->assertHasActionErrors(['confirm_combined']);

    expect(ProjectApiKey::query()->count())->toBe(0);
});

it('exports the filtered logs as CSV with masking', function () {
    $project = Project::factory()->create();
    makeAuditLog($project, ['event' => 'csv.event', 'new_values' => ['password' => 'p']]);
    $viewer = projectUser($project, 'project_viewer');
    $this->actingAs($viewer);

    $component = Livewire::test(ListAuditLogs::class)->callAction('export_csv');
    $component->assertFileDownloaded();

    expect(PlatformAudit::query()->where('action', 'logs.exported')->where('user_id', $viewer->id)->exists())->toBeTrue();
});

it('creates a user with platform roles', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Auditor', 'email' => 'auditor@example.com', 'password' => 'Str0ng!Passw0rd#',
            'is_active' => true, 'platform_roles' => ['platform_auditor'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'auditor@example.com')->first()->hasRole('platform_auditor'))->toBeTrue();
});

it('rejects a weak password', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'x', 'email' => 'weak@example.com', 'password' => 'password'])
        ->call('create')
        ->assertHasFormErrors(['password']);
});

it('lists the main resources', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListProjects::class)->assertOk();
    Livewire::test(ListSecurityEvents::class)->assertOk();
    Livewire::test(ListBackupRuns::class)->assertOk();
    Livewire::test(ListUsers::class)->assertOk();
    Livewire::test(ListRoles::class)->assertOk();
    Livewire::test(ListPlatformAudits::class)->assertOk();
});

it('neutralises spreadsheet formulas in CSV exports', function () {
    expect(AuditLogExporter::csvCell('=HYPERLINK("http://x")'))->toBe('\'=HYPERLINK("http://x")')
        ->and(AuditLogExporter::csvCell('+cmd|calc!A0'))->toBe("'+cmd|calc!A0")
        ->and(AuditLogExporter::csvCell('@SUM(1)'))->toBe("'@SUM(1)")
        ->and(AuditLogExporter::csvCell('order.paid'))->toBe('order.paid')
        ->and(AuditLogExporter::csvCell(42))->toBe(42);
});
