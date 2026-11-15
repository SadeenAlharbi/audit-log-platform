<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Support\IssuedKeyPresenter;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyManager;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * API keys of a project (plan §5, §7, §13): create (shown once), rotate,
 * revoke and allowed IPs. Keys are never edited or deleted.
 */
class ApiKeysRelationManager extends RelationManager
{
    protected static string $relationship = 'apiKeys';

    protected static ?string $title = 'مفاتيح API';

    protected static ?string $modelLabel = 'مفتاح';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user() instanceof User && auth()->user()->canInProject('api_keys.view', $ownerRecord->getKey());
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('key_prefix')->label('البادئة')->fontFamily('mono')->suffix('…'),
                TextColumn::make('environment')->label('البيئة')->badge()
                    ->formatStateUsing(fn (string $state) => ['production' => 'إنتاج', 'staging' => 'تجريبي', 'development' => 'تطوير'][$state] ?? $state)
                    ->color(fn (string $state) => $state === 'production' ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scopes')->label('الصلاحية')->badge()
                    ->formatStateUsing(fn (string $state) => [ProjectApiKey::SCOPE_WRITE => 'إرسال', ProjectApiKey::SCOPE_READ => 'قراءة'][$state] ?? $state),
                IconColumn::make('signed')->label('موقّع')->boolean()
                    ->state(fn (ProjectApiKey $record) => $record->hmac_secret !== null),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->state(fn (ProjectApiKey $record) => $record->status())
                    ->formatStateUsing(fn (string $state) => ['active' => 'نشط', 'expired' => 'منتهي', 'revoked' => 'ملغى'][$state])
                    ->color(fn (string $state) => ['active' => 'success', 'expired' => 'warning', 'revoked' => 'danger'][$state]),
                TextColumn::make('allowed_ips')->label('عناوين IP المسموحة')->badge()->placeholder('الكل')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_used_at')->label('آخر استخدام')->since()->placeholder('لم يُستخدم'),
                TextColumn::make('expires_at')->label('ينتهي')->date()->placeholder('—')
                    ->color(fn (ProjectApiKey $record) => $record->expires_at?->lessThan(now()->addDays(14)) ? 'warning' : null),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('الحالة')
                    ->options(['active' => 'نشط', 'revoked' => 'ملغى'])
                    ->default('active')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'active' => $query->whereNull('revoked_at'),
                        'revoked' => $query->whereNotNull('revoked_at'),
                        default => $query,
                    }),
            ])
            ->headerActions([
                $this->createKeyAction(),
            ])
            ->recordActions([
                // One menu per row keeps the table inside the page width.
                ActionGroup::make([
                    $this->rotateAction(),
                    $this->allowedIpsAction(),
                    $this->revokeAction(),
                ])->label('إجراءات')->tooltip('إجراءات المفتاح'),
            ]);
    }

    public function showIssuedKeyAction(): Action
    {
        return IssuedKeyPresenter::action();
    }

    private function createKeyAction(): Action
    {
        return Action::make('createKey')
            ->label('مفتاح جديد')
            ->icon(Heroicon::OutlinedKey)
            ->visible(fn () => $this->user()->can('create', [ProjectApiKey::class, $this->project()]))
            ->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(255)
                    ->placeholder('مثال: ERP production'),
                Radio::make('environment')->label('البيئة')->required()->default('production')->inline()
                    ->options(['production' => 'إنتاج (production)', 'staging' => 'تجريبي (staging)']),
                Select::make('scope')->label('الصلاحية')->required()->default('write')
                    ->options([
                        'write' => 'logs:write (إرسال السجلات)',
                        'read' => 'logs:read (الاستعلام)',
                        'both' => 'الاثنتان معًا',
                    ])->live(),
                Checkbox::make('confirm_combined')
                    ->label('أؤكد أن هذا النظام يحتاج الإرسال والاستعلام بنفس المفتاح')
                    ->visible(fn (Get $get) => $get('scope') === 'both')
                    ->accepted(fn (Get $get) => $get('scope') === 'both'),
                Toggle::make('signed')->label('توقيع HMAC إلزامي')->default(true)
                    ->helperText('موصى به للمشاريع الحساسة: يُلزم X-Signature و X-Timestamp.'),
                TagsInput::make('allowed_ips')->label('IPs مسموحة (اختياري)')
                    ->placeholder('203.0.113.10 أو 10.0.0.0/24')
                    ->visible(fn () => $this->user()->canInProject('api_keys.manage_ips', $this->project())),
            ])
            ->action(function (array $data) {
                $scopes = match ($data['scope']) {
                    'both' => [ProjectApiKey::SCOPE_WRITE, ProjectApiKey::SCOPE_READ],
                    'read' => [ProjectApiKey::SCOPE_READ],
                    default => [ProjectApiKey::SCOPE_WRITE],
                };

                try {
                    $issued = app(ApiKeyManager::class)->create(
                        project: $this->project(),
                        name: $data['name'],
                        scopes: $scopes,
                        environment: $data['environment'],
                        withSignature: (bool) $data['signed'],
                        allowedIps: $data['allowed_ips'] ?? null,
                        by: $this->user(),
                        allowCombinedScopes: (bool) ($data['confirm_combined'] ?? false),
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title('لم يُنشأ المفتاح')->body($e->getMessage())->danger()->send();

                    return;
                }

                $this->replaceMountedAction('showIssuedKey', IssuedKeyPresenter::arguments($issued));
            });
    }

    private function rotateAction(): Action
    {
        return Action::make('rotate')
            ->label('تدوير')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(fn () => 'سيُنشأ مفتاح جديد بنفس الإعدادات، ويبقى المفتاح الحالي صالحًا '.config('audit.api_keys.rotation_grace_hours').' ساعة فقط حتى تُحدّث النظام.')
            ->visible(fn (ProjectApiKey $record) => $this->user()->can('rotate', $record))
            ->action(function (ProjectApiKey $record) {
                $issued = app(ApiKeyManager::class)->rotate($record, $this->user());
                $this->replaceMountedAction('showIssuedKey', IssuedKeyPresenter::arguments($issued));
            });
    }

    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('إلغاء')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('إلغاء المفتاح فورًا')
            ->modalDescription('كل الطلبات بهذا المفتاح ستُرفض من الآن. لا يمكن التراجع.')
            ->schema([
                Textarea::make('reason')->label('السبب')->rows(2)->maxLength(255),
            ])
            ->visible(fn (ProjectApiKey $record) => $this->user()->can('revoke', $record))
            ->action(function (ProjectApiKey $record, array $data) {
                app(ApiKeyManager::class)->revoke($record, $this->user(), $data['reason'] ?? null);
                Notification::make()->title('تم إلغاء المفتاح')->success()->send();
            });
    }

    private function allowedIpsAction(): Action
    {
        return Action::make('allowedIps')
            ->label('IPs المسموحة')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('gray')
            ->fillForm(fn (ProjectApiKey $record) => ['allowed_ips' => $record->allowed_ips ?? []])
            ->schema([
                TagsInput::make('allowed_ips')->label('العناوين أو النطاقات (CIDR)')
                    ->helperText('اتركها فارغة للسماح من أي عنوان.'),
            ])
            ->visible(fn (ProjectApiKey $record) => $this->user()->can('manageIps', $record))
            ->action(function (ProjectApiKey $record, array $data) {
                try {
                    app(ApiKeyManager::class)->updateAllowedIps($record, $data['allowed_ips'] ?? null, $this->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title('قائمة غير صالحة')->body($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('تم تحديث القائمة')->success()->send();
            });
    }

    private function project(): Project
    {
        /** @var Project */
        return $this->getOwnerRecord();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
