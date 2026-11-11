<?php

namespace App\Filament\Resources\Projects\RelationManagers;

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
                        Select::make('role_id')->label('الدور')->required()->options($roles),
                    ])
                    ->action(function (array $data) {
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
                    ->visible(fn () => $this->canManage())
                    ->fillForm(fn (User $record) => ['role_id' => $record->pivot->role_id])
                    ->schema([Select::make('role_id')->label('الدور')->required()->options($roles)])
                    ->action(function (User $record, array $data) {
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
                    ->visible(fn () => $this->canManage())
                    ->action(function (User $record) {
                        $this->project()->members()->detach($record->id);
                        app(PlatformAuditLogger::class)->record('project.member_removed', $this->project(), ['user_id' => $record->id]);
                    }),
            ]);
    }

    /** @return array<int, string> role id => label */
    public static function projectRoles(): array
    {
        $labels = ['project_owner' => 'Project Owner', 'project_viewer' => 'Project Viewer'];

        return Role::query()->where('scope', 'project')->orderBy('name')->pluck('name', 'id')
            ->map(fn (string $name) => $labels[$name] ?? $name)
            ->all();
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
