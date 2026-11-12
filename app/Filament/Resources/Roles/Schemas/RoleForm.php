<?php

namespace App\Filament\Resources\Roles\Schemas;

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
    public const GROUP_LABELS = [
        'projects' => 'المشاريع', 'api_keys' => 'مفاتيح API', 'logs' => 'السجلات', 'users' => 'المستخدمون',
        'roles' => 'الأدوار', 'backups' => 'النسخ الاحتياطي', 'security_events' => 'الأمان', 'settings' => 'الإعدادات',
    ];

    public static function configure(Schema $schema): Schema
    {
        $groups = Permission::query()->orderBy('name')->pluck('name')
            ->groupBy(fn (string $name) => explode('.', $name)[0]);

        return $schema->components([
            Section::make('الدور')->columns(2)->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(125)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->unique(Role::class, 'name', ignoreRecord: true)
                    ->disabledOn('edit'),
                Radio::make('scope')->label('النطاق')->required()->default('platform')->inline()
                    ->options(['platform' => 'المنصة (كل المشاريع)', 'project' => 'مشروع (يُسند من صفحة المشروع)'])
                    ->disabledOn('edit'),
                TextInput::make('guard_name')->default('web')->hidden()->dehydratedWhenHidden(),
            ]),
            Section::make('الصلاحيات')->columns(4)->schema(
                $groups->map(fn ($names, $group) => CheckboxList::make("permissions_{$group}")
                    ->label(self::GROUP_LABELS[$group] ?? $group)
                    ->options($names->mapWithKeys(fn ($n) => [$n => $n])->all())
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
