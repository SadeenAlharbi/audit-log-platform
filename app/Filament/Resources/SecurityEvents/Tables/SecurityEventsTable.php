<?php

namespace App\Filament\Resources\SecurityEvents\Tables;

use App\Filament\Support\Labels;
use App\Models\Project;
use App\Services\Security\SecurityEventRecorder;
use App\Support\Time\DisplayTime;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SecurityEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('reason')->label('السبب')->badge()->color('danger')
                    ->formatStateUsing(fn (string $state) => Labels::reason($state)),
                // Summary rows (D21) stand for many attempts of the same minute.
                TextColumn::make('request_meta.suppressed')->label('المحاولات')->numeric()->default(1)
                    ->badge()->color(fn ($state) => (int) $state > 1 ? 'warning' : 'gray'),
                TextColumn::make('ip')->label('عنوان IP')->searchable()->fontFamily('mono')->copyable(),
                TextColumn::make('project.name')->label('المشروع')->placeholder('غير معروف'),
                TextColumn::make('apiKey.key_prefix')->label('المفتاح')->fontFamily('mono')->placeholder('—'),
                TextColumn::make('request_meta.path')->label('المسار')->fontFamily('mono')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('reason')->label('السبب')->multiple()
                    ->options(collect(SecurityEventRecorder::REASONS)->mapWithKeys(fn (string $r) => [$r => Labels::reason($r)])->all()),
                SelectFilter::make('project_id')->label('المشروع')
                    ->options(fn () => Project::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('من')->default(fn () => DisplayTime::now()->subDays(7)->toDateString()),
                        DatePicker::make('until')->label('إلى'),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        // Local days (DisplayTime) converted to UTC ranges.
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', DisplayTime::dayRangeUtc($d)[0]))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', DisplayTime::dayRangeUtc($d)[1]))),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }
}
