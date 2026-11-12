<?php

use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\SecurityEvent;
use App\Models\User;
use Spatie\Permission\Models\Role;

/*
| The roles matrix of plan §13, as executable checks. Each row is one role;
| "A" is a project the user belongs to, "B" a project they do not.
*/

beforeEach(function () {
    $this->a = Project::factory()->create();
    $this->b = Project::factory()->create();
    $this->logA = makeAuditLog($this->a);
    $this->logB = makeAuditLog($this->b);
    $this->keyA = issueKey($this->a)->key;
    $this->keyB = issueKey($this->b)->key;
});

dataset('roles', [
    'super_admin' => fn () => platformUser('super_admin'),
    'platform_auditor' => fn () => platformUser('platform_auditor'),
    'security_officer' => fn () => platformUser('security_officer'),
    'project_owner of A' => fn () => projectUser($this->a, 'project_owner'),
    'project_viewer of A' => fn () => projectUser($this->a, 'project_viewer'),
]);

it('nobody can create, edit or delete an audit log', function (User $user) {
    expect($user->can('create', AuditLog::class))->toBeFalse()
        ->and($user->can('update', $this->logA))->toBeFalse()
        ->and($user->can('delete', $this->logA))->toBeFalse()
        ->and($user->can('delete', $this->a))->toBeFalse()
        ->and($user->can('delete', $this->keyA))->toBeFalse();
})->with('roles');

it('grants exactly the abilities of the matrix', function (string $role, array $expected) {
    $user = str_starts_with($role, 'project_') ? projectUser($this->a, $role) : platformUser($role);

    $actual = [
        'view logs A' => $user->can('view', $this->logA),
        'view logs B' => $user->can('view', $this->logB),
        'export A' => $user->can('export', [AuditLog::class, $this->a->id]),
        'sensitive A' => $user->can('viewSensitive', $this->logA),
        'create project' => $user->can('create', Project::class),
        'update A' => $user->can('update', $this->a),
        'update B' => $user->can('update', $this->b),
        'archive A' => $user->can('archive', $this->a),
        'create key A' => $user->can('create', [ProjectApiKey::class, $this->a]),
        'revoke key A' => $user->can('revoke', $this->keyA),
        'revoke key B' => $user->can('revoke', $this->keyB),
        'manage IPs A' => $user->can('manageIps', $this->keyA),
        'security events' => $user->can('viewAny', SecurityEvent::class),
        'users' => $user->can('viewAny', User::class),
        'roles' => $user->can('viewAny', Role::class),
        'backups view' => $user->can('viewAny', BackupRun::class),
        'backups run' => $user->can('run', BackupRun::class),
    ];

    expect($actual)->toBe($expected);
})->with([
    'super_admin' => ['super_admin', [
        'view logs A' => true, 'view logs B' => true, 'export A' => true, 'sensitive A' => true,
        'create project' => true, 'update A' => true, 'update B' => true, 'archive A' => true,
        'create key A' => true, 'revoke key A' => true, 'revoke key B' => true, 'manage IPs A' => true,
        'security events' => true, 'users' => true, 'roles' => true, 'backups view' => true, 'backups run' => true,
    ]],
    'platform_auditor' => ['platform_auditor', [
        'view logs A' => true, 'view logs B' => true, 'export A' => true, 'sensitive A' => false,
        'create project' => false, 'update A' => false, 'update B' => false, 'archive A' => false,
        'create key A' => false, 'revoke key A' => false, 'revoke key B' => false, 'manage IPs A' => false,
        'security events' => false, 'users' => false, 'roles' => false, 'backups view' => false, 'backups run' => false,
    ]],
    'security_officer' => ['security_officer', [
        'view logs A' => false, 'view logs B' => false, 'export A' => false, 'sensitive A' => false,
        'create project' => false, 'update A' => false, 'update B' => false, 'archive A' => false,
        'create key A' => false, 'revoke key A' => false, 'revoke key B' => false, 'manage IPs A' => true,
        'security events' => true, 'users' => false, 'roles' => false, 'backups view' => false, 'backups run' => false,
    ]],
    'project_owner' => ['project_owner', [
        'view logs A' => true, 'view logs B' => false, 'export A' => true, 'sensitive A' => false,
        'create project' => false, 'update A' => true, 'update B' => false, 'archive A' => false,
        'create key A' => true, 'revoke key A' => true, 'revoke key B' => false, 'manage IPs A' => true,
        'security events' => false, 'users' => false, 'roles' => false, 'backups view' => false, 'backups run' => false,
    ]],
    'project_viewer' => ['project_viewer', [
        'view logs A' => true, 'view logs B' => false, 'export A' => true, 'sensitive A' => false,
        'create project' => false, 'update A' => false, 'update B' => false, 'archive A' => false,
        'create key A' => false, 'revoke key A' => false, 'revoke key B' => false, 'manage IPs A' => false,
        'security events' => false, 'users' => false, 'roles' => false, 'backups view' => false, 'backups run' => false,
    ]],
]);

it('scopes queries automatically to the projects a user may see (global scope)', function () {
    $viewer = projectUser($this->a, 'project_viewer');
    $this->actingAs($viewer);

    expect(AuditLog::query()->pluck('id')->all())->toBe([$this->logA->id])
        ->and(Project::query()->pluck('id')->all())->toBe([$this->a->id])
        // A viewer has no api_keys.view, so even project A's keys are hidden.
        ->and(ProjectApiKey::query()->pluck('id')->all())->toBe([]);

    $this->actingAs(projectUser($this->a, 'project_owner'));
    expect(ProjectApiKey::query()->pluck('id')->all())->toBe([$this->keyA->id]);

    $this->actingAs(platformUser('platform_auditor'));
    expect(AuditLog::query()->count())->toBe(2);
});

it('gives no access to a deactivated user', function () {
    $owner = projectUser($this->a, 'project_owner');
    $owner->forceFill(['is_active' => false])->save();

    expect($owner->can('view', $this->logA))->toBeFalse()
        ->and($owner->projectIdsWith('logs.view'))->toBe([]);
});

it('loses project access when the project is archived', function () {
    $viewer = projectUser($this->a, 'project_viewer');
    $this->a->archive();

    expect($viewer->fresh()->can('view', $this->logA))->toBeFalse();
});

it('does not let a user manager take over a more privileged account', function () {
    $manager = platformUser('platform_auditor');
    $managerRole = Role::findOrCreate('user_manager', 'web');
    $managerRole->syncPermissions(['users.manage', 'roles.manage']);
    $manager->assignRole($managerRole);
    $admin = platformUser('super_admin');
    $viewer = projectUser($this->a, 'project_viewer');

    expect($manager->can('update', $admin))->toBeFalse()
        ->and($manager->can('assignRoles', $admin))->toBeFalse()
        ->and($manager->can('update', $viewer))->toBeTrue()
        // cannot edit a role they hold, nor one with permissions they lack
        ->and($manager->can('update', $managerRole))->toBeFalse()
        ->and($manager->can('update', Role::findByName('security_officer')))->toBeFalse()
        ->and($manager->can('update', Role::findByName('project_viewer')))->toBeTrue();
});

it('requires 2FA for every platform-wide role, not for project members', function () {
    expect(platformUser('super_admin')->requiresMultiFactorAuthentication())->toBeTrue()
        ->and(platformUser('security_officer')->requiresMultiFactorAuthentication())->toBeTrue()
        ->and(platformUser('platform_auditor')->requiresMultiFactorAuthentication())->toBeTrue()
        ->and(projectUser($this->a, 'project_owner')->requiresMultiFactorAuthentication())->toBeFalse()
        ->and(projectUser($this->a, 'project_viewer')->requiresMultiFactorAuthentication())->toBeFalse();
});
