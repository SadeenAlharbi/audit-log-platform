<?php

namespace App\Filament\Resources\Projects\Actions;

use App\Models\Project;
use App\Services\Ingestion\TestLogSender;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Plan §5: "Send test log" button to verify the pipeline end to end.
 */
class SendTestLogAction
{
    public static function make(): Action
    {
        return Action::make('sendTestLog')
            ->label('إرسال سجل تجريبي')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->visible(fn (Project $record) => $record->archived_at === null && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (Project $record) {
                try {
                    $result = app(TestLogSender::class)->send($record, auth()->user());
                } catch (Throwable $e) {
                    Notification::make()->title('فشل الإرسال')->body($e->getMessage())->danger()->send();

                    return;
                }

                $result['stored']
                    ? Notification::make()->title('وصل السجل التجريبي وتم تخزينه')->body('المعرّف: '.$result['uid'])->success()->send()
                    : Notification::make()->title('قُبل السجل وهو في الطابور')->body('المعرّف: '.$result['uid'].' — تأكد أن queue-worker يعمل.')->warning()->send();
            });
    }
}
