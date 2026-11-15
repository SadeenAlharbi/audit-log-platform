<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Filament\Support\Labels;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleForm
{
    public const GROUP_LABELS = Labels::PERMISSION_GROUPS;

    public static function configure(Schema $schema): Schema
    {
        $groups = Permission::query()->orderBy('name')->pluck('name')
            ->groupBy(fn (string $name) => explode('.', $name)[0]);

        return $schema->components([
            Section::make('الدور')->columnSpanFull()->columns(2)->schema([
                TextInput::make('name')->label('الاسم البرمجي')->required()->maxLength(125)
                    ->helperText(fn (?Role $record) => $record && isset(Labels::ROLES[$record->name])
                        ? 'يظهر في اللوحة باسم: '.Labels::ROLES[$record->name]
                        : 'حروف إنجليزية صغيرة وأرقام و _ فقط، مثل: report_viewer')
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->unique(Role::class, 'name', ignoreRecord: true)
                    ->disabledOn('edit'),
                Radio::make('scope')->label('النطاق')->required()->default('platform')->inline()
                    ->options(['platform' => 'المنصة (كل المشاريع)', 'project' => 'مشروع (يُسند من صفحة المشروع)'])
                    ->disabledOn('edit'),
                TextInput::make('guard_name')->default('web')->hidden()->dehydratedWhenHidden(),
            ]),
            Section::make('الصلاحيات')
                ->description('لا يمكنك منح صلاحية لا تملكها أنت.')
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->schema(
                    $groups->map(fn ($names, $group) => CheckboxList::make("permissions_{$group}")
                        ->label(self::GROUP_LABELS[$group] ?? $group)
                        ->options($names->mapWithKeys(fn ($n) => [$n => Labels::permission($n)])->all())
                        ->descriptions($names->mapWithKeys(fn ($n) => [$n => $n])->all())
                        ->bulkToggleable()
                        ->afterStateHydrated(fn (CheckboxList $component, ?Role $record) => $component->state(
                            $record?->permissions->pluck('name')->filter(fn ($n) => str_starts_with($n, $group.'.'))->values()->all() ?? []
                        ))
                        ->dehydrated(false))->values()->all()
                ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  the raw form state
     * @return list<string>
     */
    public static function selectedPermissions(array $data): array
    {
        // Security review M6: a role can only receive permissions the editor
        // holds, whatever the submitted form contains.
        $grantable = auth()->user() instanceof User ? auth()->user()->globalPermissionNames() : [];

        return collect($data)
            ->filter(fn ($v, $k) => str_starts_with((string) $k, 'permissions_') && is_array($v))
            ->flatten()
            ->filter(fn ($name) => in_array($name, $grantable, true))
            ->values()
            ->all();
    }
}
