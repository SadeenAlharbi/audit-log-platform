<?php

namespace App\Filament\Resources\PlatformAudits\Pages;

use App\Filament\Resources\PlatformAudits\PlatformAuditResource;
use App\Services\Audit\PlatformAuditLogger;
use App\Services\Security\AccessReview;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPlatformAudits extends ListRecords
{
    protected static string $resource = PlatformAuditResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Plan §13: Access Review every 3 months, exportable.
            Action::make('accessReview')
                ->label('مراجعة الصلاحيات (CSV)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function () {
                    $csv = app(AccessReview::class)->csv();
                    app(PlatformAuditLogger::class)->record('access.review_exported', 'users');

                    return response()->streamDownload(fn () => print ($csv), 'access-review-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }
}
