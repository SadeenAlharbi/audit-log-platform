<?php

use App\Filament\Resources\PlatformAudits\Pages\ViewPlatformAudit;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\SecurityEvents\Pages\ViewSecurityEvent;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Widgets\ActiveProjectsTable;
use App\Filament\Widgets\LogVolumeChart;
use App\Filament\Widgets\PlatformStats;
use App\Filament\Widgets\TopEventsTable;
use App\Models\AuditLog;
use App\Models\PlatformAudit;
use App\Models\Project;
use App\Models\SecurityEvent;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = platformUser('super_admin');
    $this->admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->actingAs($this->admin);
});

it('renders the dashboard widgets with data', function () {
    $project = Project::factory()->create(['name' => 'ERP']);
    makeAuditLog($project, ['event' => 'order.paid', 'severity' => 'critical']);

    Livewire::test(PlatformStats::class)->assertOk()->assertSee('سجلات اليوم');
    Livewire::test(LogVolumeChart::class)->assertOk();
    Livewire::test(TopEventsTable::class)->assertOk()->assertSee('order.paid');
    Livewire::test(ActiveProjectsTable::class)->assertOk()->assertSee('ERP');
});

it('manages project members and records it', function () {
    $project = Project::factory()->create();
    $member = User::factory()->create();
    $viewer = Role::findByName('project_viewer');
    $owner = Role::findByName('project_owner');

    $rm = Livewire::test(MembersRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class]);

    $rm->callAction(TestAction::make('addMember')->table(), ['user_id' => $member->id, 'role_id' => $viewer->id])->assertHasNoActionErrors();
    expect($member->fresh()->canInProject('logs.view', $project))->toBeTrue();

    $rm->callAction(TestAction::make('changeRole')->table($member), ['role_id' => $owner->id]);
    expect($member->fresh()->canInProject('api_keys.create', $project))->toBeTrue();

    $rm->callAction(TestAction::make('removeMember')->table($member));
    expect($member->fresh()->canInProject('logs.view', $project))->toBeFalse();

    expect(PlatformAudit::query()->whereIn('action', ['project.member_added', 'project.member_role_changed', 'project.member_removed'])->count())->toBe(3);
});

it('sends a test log from the project page', function () {
    $project = Project::factory()->create();

    Livewire::test(ViewProject::class, ['record' => $project->id])
        ->callAction('sendTestLog')
        ->assertNotified('وصل السجل التجريبي وتم تخزينه');

    expect(AuditLog::query()->where('project_id', $project->id)->where('event', 'platform.test')->exists())->toBeTrue();
});

it('archives a project and revokes its keys', function () {
    $project = Project::factory()->create();
    $key = issueKey($project)->key;

    Livewire::test(ViewProject::class, ['record' => $project->id])->callAction('archive');

    expect($project->fresh()->archived_at)->not->toBeNull()
        ->and($key->fresh()->revoked_at)->not->toBeNull();
});

it('edits a project but never its slug', function () {
    $project = Project::factory()->create(['slug' => 'fixed-slug']);

    Livewire::test(EditProject::class, ['record' => $project->id])
        ->fillForm(['name' => 'Renamed', 'retention_days' => 90])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($project->fresh())->name->toBe('Renamed')->slug->toBe('fixed-slug')->retention_days->toBe(90);
});

it('rejects a retention above the platform maximum', function () {
    $project = Project::factory()->create();

    Livewire::test(EditProject::class, ['record' => $project->id])
        ->fillForm(['retention_days' => 5000])
        ->call('save')
        ->assertHasFormErrors(['retention_days']);
});

it('creates and edits a custom role', function () {
    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'compliance', 'scope' => 'platform', 'permissions_logs' => ['logs.view', 'logs.export']])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::findByName('compliance');
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['logs.export', 'logs.view']);

    Livewire::test(EditRole::class, ['record' => $role->id])
        ->fillForm(['permissions_logs' => ['logs.view']])
        ->call('save');

    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['logs.view']);
});

it('cannot change the super_admin role or delete built-in roles', function () {
    $super = Role::findByName('super_admin');

    expect($this->admin->can('update', $super))->toBeFalse()
        ->and($this->admin->can('delete', Role::findByName('project_viewer')))->toBeFalse();
});

it('edits a user, deactivates them and changes platform roles', function () {
    $user = platformUser('platform_auditor');

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['is_active' => false, 'platform_roles' => ['security_officer']])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();
    expect($user->is_active)->toBeFalse()
        ->and($user->getRoleNames()->all())->toBe(['security_officer']);
});

it('signs out a user deactivated during their session', function () {
    $viewer = projectUser(Project::factory()->create(), 'project_viewer');
    $this->actingAs($viewer)->get('/admin/audit-logs')->assertOk();

    $viewer->forceFill(['is_active' => false])->save();

    $this->get('/admin/audit-logs')->assertRedirect();
});

it('shows a security event and a platform audit entry', function () {
    $event = SecurityEvent::query()->create(['ip' => '1.2.3.4', 'reason' => 'invalid_key', 'request_meta' => ['path' => '/api/v1/logs']]);
    $entry = PlatformAudit::query()->create(['action' => 'x.y', 'changes' => ['a' => 1]]);

    Livewire::test(ViewSecurityEvent::class, ['record' => $event->id])->assertOk()->assertSee('1.2.3.4');
    Livewire::test(ViewPlatformAudit::class, ['record' => $entry->id])->assertOk()->assertSee('x.y');
});

it('opens Horizon only for super admins', function () {
    $this->get('/horizon')->assertOk();

    $this->actingAs(projectUser(Project::factory()->create(), 'project_owner'))->get('/horizon')->assertForbidden();
});
