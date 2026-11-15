<?php

namespace App\Filament\Resources\Projects\Actions;

use App\Models\Project;
use App\Models\User;
use App\Services\Ingestion\TestLogSender;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
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
            // It issues a (temporary) write key, so it needs the key permission too.
            ->visible(function (Project $record): bool {
                $user = auth()->user();

                return $record->archived_at === null && $user instanceof User
                    && $user->can('update', $record) && $user->canInProject('api_keys.create', $record);
            })
            ->action(function (Project $record) {
                $limiter = 'test-log:'.auth()->id();
                if (RateLimiter::tooManyAttempts($limiter, 5)) {
                    Notification::make()->title('محاولات كثيرة')->body('أعد المحاولة بعد دقيقة.')->warning()->send();

                    return;
                }
                RateLimiter::hit($limiter, 60);

                try {
                    $result = app(TestLogSender::class)->send($record, auth()->user());
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title('فشل الإرسال')->body('حدث خطأ داخلي. التفاصيل في سجل التطبيق.')->danger()->send();

                    return;
                }

                match (true) {
                    $result['status'] !== 202 => Notification::make()->title('رفضت الـ API السجل التجريبي')->body('رمز الاستجابة: '.$result['status'])->danger()->send(),
                    $result['stored'] => Notification::make()->title('وصل السجل التجريبي وتم تخزينه')->body('مرّ عبر الـ API كاملة (المفتاح، التوقيع، التحقق، الطابور). المعرّف: '.$result['uid'])->success()->send(),
                    default => Notification::make()->title('قُبل السجل وهو في الطابور')->body('المعرّف: '.$result['uid'].' — تأكد أن queue-worker يعمل.')->warning()->send(),
                };
            });
    }
}
