<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserForm
{
    public const PLATFORM_ROLE_LABELS = [
        'super_admin' => 'Super Admin — كل شيء',
        'platform_auditor' => 'Platform Auditor — قراءة كل السجلات',
        'security_officer' => 'Security Officer — المحاولات المرفوضة وقوائم IP',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الحساب')->columns(2)->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(255),
                TextInput::make('email')->label('البريد')->email()->required()->maxLength(255)
                    ->unique(User::class, 'email', ignoreRecord: true),
                TextInput::make('password')->label('كلمة المرور')->password()->revealable()
                    ->rule(Password::defaults())
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText('12 حرفًا على الأقل، بأحرف كبيرة وصغيرة وأرقام ورموز.'),
                Toggle::make('is_active')->label('نشط')->default(true)
                    ->helperText('المستخدم المعطّل لا يستطيع الدخول. المستخدمون لا يُحذفون.'),
            ]),
            Section::make('أدوار المنصة')
                ->description('أدوار المشاريع (Owner / Viewer) تُدار من صفحة كل مشروع.')
                ->visible(fn (?User $record) => auth()->user()?->canGlobally('roles.manage') && ! auth()->user()->is($record))
                ->schema([
                    CheckboxList::make('platform_roles')
                        ->hiddenLabel()
                        ->options(fn () => Role::query()->where('scope', 'platform')->orderBy('name')->pluck('name', 'name')
                            ->mapWithKeys(fn ($n) => [$n => self::PLATFORM_ROLE_LABELS[$n] ?? $n])->all())
                        ->afterStateHydrated(fn (CheckboxList $component, ?User $record) => $component->state($record?->roles->pluck('name')->all() ?? []))
                        ->dehydrated(false),
                ]),
        ]);
    }
}
