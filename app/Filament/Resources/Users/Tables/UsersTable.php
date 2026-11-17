<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Support\Labels;
use App\Models\User;
use App\Services\Audit\PlatformAuditLogger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable()->sortable(),
                TextColumn::make('email')->label('البريد')->searchable(),
                TextColumn::make('roles.name')->label('أدوار المنصة')->badge()->placeholder('—')
                    ->formatStateUsing(fn (string $state) => Labels::role($state)),
                IconColumn::make('is_active')->label('نشط')->boolean(),
                IconColumn::make('mfa')->label('المصادقة الثنائية')->boolean()
                    ->state(fn (User $record) => filled($record->app_authentication_secret)),
                TextColumn::make('locked_until')->label('مقفل حتى')->dateTime('Y-m-d H:i')->placeholder('—')->color('danger'),
                TextColumn::make('last_login_at')->label('آخر دخول')->since()->placeholder('لم يدخل'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('نشط'),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('unlock')->label('فك القفل')->icon(Heroicon::OutlinedLockOpen)
                        ->visible(fn (User $record) => $record->isLocked() && auth()->user()->can('update', $record))
                        ->action(function (User $record) {
                            $record->forceFill(['locked_until' => null, 'failed_login_attempts' => 0])->saveQuietly();
                            app(PlatformAuditLogger::class)->record('user.unlocked', $record);
                            Notification::make()->title('تم فك القفل')->success()->send();
                        }),
                    Action::make('resetMfa')->label('إعادة ضبط 2FA')->icon(Heroicon::OutlinedShieldExclamation)->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('سيُطلب من المستخدم إعداد المصادقة الثنائية من جديد.')
                        ->visible(fn (User $record) => filled($record->app_authentication_secret) && auth()->user()->can('update', $record))
                        ->action(function (User $record) {
                            $record->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->saveQuietly();
                            app(PlatformAuditLogger::class)->record('user.mfa_reset', $record);
                            Notification::make()->title('تمت إعادة ضبط 2FA')->success()->send();
                        }),
                ]),
            ])
            ->toolbarActions([]);
    }
}
