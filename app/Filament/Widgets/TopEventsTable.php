<?php

namespace App\Filament\Widgets;

use App\Models\AuditLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TopEventsTable extends TableWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'أعلى الأحداث (7 أيام)';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AuditLog::query()
                ->where('occurred_at', '>=', now()->utc()->subDays(7))
                ->selectRaw('event, COUNT(*) AS total, MAX(occurred_at) AS last_at')
                ->groupBy('event')
                ->orderByDesc('total')
                ->limit(50))
            ->paginated(false)
            ->columns([
                TextColumn::make('event')->label('الحدث')->fontFamily('mono'),
                TextColumn::make('total')->label('العدد')->numeric(),
                TextColumn::make('last_at')->label('آخر مرة')->since(),
            ]);
    }

    public function getTableRecordKey(Model|array $record): string
    {
        return (string) (is_array($record) ? $record['event'] : $record->getAttribute('event'));
    }
}
