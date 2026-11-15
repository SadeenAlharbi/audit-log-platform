<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Support\Labels;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\PlatformAuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Project-level membership (project_user): Project Owner / Project Viewer.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'الأعضاء';

    protected static ?string $modelLabel = 'عضو';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user() instanceof User && auth()->user()->canInProject('projects.view', $ownerRecord->getKey());
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $roles = self::projectRoles();
        $grantable = fn (): array => array_intersect_key($roles, array_flip($this->grantableRoleIds()));

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('email')->label('البريد')->searchable(),
                TextColumn::make('pivot.role_id')->label('الدور')->badge()
                    ->formatStateUsing(fn ($state) => $roles[$state] ?? '—'),
                TextColumn::make('pivot.created_at')->label('أُضيف')->since(),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label('إضافة عضو')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->visible(fn () => $this->canManage())
                    ->schema([
                        Select::make('user_id')->label('المستخدم')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => User::query()->active()
                                ->whereNotIn('id', $this->project()->members()->pluck('users.id'))
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                                ->limit(20)->pluck('email', 'id'))
                            ->getOptionLabelUsing(fn ($value) => User::query()->find($value)?->email),
                        Select::make('role_id')->label('الدور')->required()->options($grantable),
                    ])
                    ->action(function (array $data) {
                        $this->ensureGrantable((int) $data['role_id']);
                        $this->project()->members()->attach($data['user_id'], [
                            'role_id' => $data['role_id'],
                            'added_by' => auth()->id(),
                        ]);
                        app(PlatformAuditLogger::class)->record('project.member_added', $this->project(), [
                            'user_id' => (int) $data['user_id'],
                            'role' => self::projectRoles()[$data['role_id']] ?? $data['role_id'],
                        ]);
                        Notification::make()->title('تمت إضافة العضو')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('تغيير الدور')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (User $record) => $this->canManageMember($record))
                    ->fillForm(fn (User $record) => ['role_id' => $record->pivot->role_id])
                    ->schema([Select::make('role_id')->label('الدور')->required()->options($grantable)])
                    ->action(function (User $record, array $data) {
                        abort_unless($this->canManageMember($record), 403);
                        $this->ensureGrantable((int) $data['role_id']);
                        $old = $record->pivot->role_id;
                        $this->project()->members()->updateExistingPivot($record->id, ['role_id' => $data['role_id']]);
                        app(PlatformAuditLogger::class)->record('project.member_role_changed', $this->project(), [
                            'user_id' => $record->id,
                            'old' => self::projectRoles()[$old] ?? $old,
                            'new' => self::projectRoles()[$data['role_id']] ?? $data['role_id'],
                        ]);
                    }),
                Action::make('removeMember')
                    ->label('إزالة')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $this->canManageMember($record))
                    ->action(function (User $record) {
                        abort_unless($this->canManageMember($record), 403);
                        $this->project()->members()->detach($record->id);
                        app(PlatformAuditLogger::class)->record('project.member_removed', $this->project(), ['user_id' => $record->id]);
                    }),
            ]);
    }

    /** @return array<int, string> role id => label */
    public static function projectRoles(): array
    {
        return Role::query()->where('scope', 'project')->orderBy('name')->pluck('name', 'id')
            ->map(fn (string $name) => Labels::role($name))
            ->all();
    }

    /**
     * Security review M2: a member manager can only hand out project roles
     * whose permissions they hold on this project themselves.
     *
     * @return list<int>
     */
    public function grantableRoleIds(): array
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return [];
        }
        $projectId = (int) $this->project()->getKey();

        return Role::query()->where('scope', 'project')->with('permissions')->get()
            ->filter(fn (Role $role) => $role->permissions->every(fn ($p) => $actor->canInProject($p->name, $projectId)))
            ->map(fn (Role $role) => (int) $role->id)->values()->all();
    }

    private function ensureGrantable(int $roleId): void
    {
        abort_unless(in_array($roleId, $this->grantableRoleIds(), true), 403);
    }

    /** Not one's own membership, and not a member holding a role the actor could not grant. */
    private function canManageMember(User $record): bool
    {
        return $this->canManage()
            && $record->getKey() !== auth()->id()
            && in_array((int) $record->pivot->role_id, $this->grantableRoleIds(), true);
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('manageMembers', $this->project()) ?? false;
    }

    private function project(): Project
    {
        /** @var Project */
        return $this->getOwnerRecord();
    }
}
