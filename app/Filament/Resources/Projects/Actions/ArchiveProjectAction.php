<?php

namespace App\Filament\Resources\Projects\Actions;

use App\Models\Project;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Projects are archived, never deleted (decision D1). Archiving stops the
 * project and revokes its keys; its logs stay until retention expires.
 */
class ArchiveProjectAction
{
    public static function make(): Action
    {
        return Action::make('archive')
            ->label('أرشفة')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('أرشفة المشروع')
            ->modalDescription('سيتوقف المشروع عن استقبال السجلات وتُلغى كل مفاتيحه. السجلات الموجودة تبقى حتى انتهاء مدة الاحتفاظ. لا يمكن التراجع من اللوحة.')
            ->visible(fn (Project $record) => auth()->user()?->can('archive', $record) ?? false)
            ->action(function (Project $record) {
                /** @var User $user */
                $user = auth()->user();

                DB::transaction(function () use ($record, $user) {
                    foreach ($record->apiKeys()->withoutGlobalScopes()->whereNull('revoked_at')->get() as $key) {
                        app(ApiKeyManager::class)->revoke($key, $user, 'project archived');
                    }
                    $record->archive($user);
                });

                Notification::make()->title('تمت أرشفة المشروع')->success()->send();
            });
    }
}
