<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Support\Labels;
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
        'super_admin' => 'كل الصلاحيات، ومنها إدارة المستخدمين والأدوار',
        'platform_auditor' => 'قراءة سجلات كل المشاريع وتصديرها، بدون أي تعديل',
        'security_officer' => 'المحاولات المرفوضة وتقييد عناوين IP للمفاتيح',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الحساب')->columnSpanFull()->columns(['default' => 1, 'lg' => 3])->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(255),
                TextInput::make('email')->label('البريد')->email()->required()->maxLength(255)
                    ->unique(User::class, 'email', ignoreRecord: true),
                TextInput::make('password')->label('كلمة المرور')->password()->revealable()
                    ->rule(Password::defaults())
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => ($operation === 'edit' ? 'اتركها فارغة لإبقاء كلمة المرور الحالية. ' : '').'12 حرفًا على الأقل، فيها حروف كبيرة وصغيرة وأرقام ورموز.'),
                Toggle::make('is_active')->label('الحساب نشط')->default(true)->inline(false)
                    ->helperText('الحساب الموقوف لا يستطيع الدخول. الحسابات لا تُحذف.')
                    ->columnSpanFull(),
            ]),
            Section::make('أدوار المنصة')
                ->description('أدوار المشاريع (مالك أو مشاهد) تُدار من صفحة كل مشروع.')
                ->columnSpanFull()
                ->visible(fn (?User $record) => auth()->user()?->canGlobally('roles.manage') && ! auth()->user()->is($record))
                ->schema([
                    CheckboxList::make('platform_roles')
                        ->label('أدوار المنصة')->hiddenLabel()
                        ->options(fn () => Role::query()->where('scope', 'platform')->orderBy('name')->pluck('name', 'name')
                            ->mapWithKeys(fn ($n) => [$n => Labels::role($n)])->all())
                        ->descriptions(fn () => Role::query()->where('scope', 'platform')->pluck('name')
                            ->mapWithKeys(fn ($n) => [$n => self::PLATFORM_ROLE_LABELS[$n] ?? ''])->all())
                        ->columns(['default' => 1, 'md' => 3])
                        ->afterStateHydrated(fn (CheckboxList $component, ?User $record) => $component->state($record?->roles->pluck('name')->all() ?? []))
                        ->dehydrated(false),
                ]),
        ]);
    }
}
