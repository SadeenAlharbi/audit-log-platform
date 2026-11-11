<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Models\AuditLog;
use App\Services\Export\AuditLogExporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->exportAction('csv', 'CSV'),
                $this->exportAction('json', 'JSON'),
            ])
                ->label('تصدير')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->visible(fn () => auth()->user()?->can('export', AuditLog::class) ?? false),
        ];
    }

    private function exportAction(string $format, string $label): Action
    {
        return Action::make("export_{$format}")
            ->label("تصدير {$label} (حسب الفلاتر)")
            ->action(function () use ($format) {
                $user = auth()->user();
                $query = $this->getFilteredSortedTableQuery();

                // Only projects where the user may export.
                $ids = $user->projectIdsWith('logs.export');
                if ($ids !== null) {
                    $query->whereIn('project_id', $ids === [] ? [0] : $ids);
                }

                return app(AuditLogExporter::class)->download($query, $format, $user);
            });
    }
}
