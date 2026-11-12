<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use App\Models\Project;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AuditLogsTable
{
    /** @var array<string, string> */
    public const SEVERITY_LABELS = ['info' => 'معلومة', 'warning' => 'تحذير', 'critical' => 'حرج'];

    public const SEVERITY_COLORS = ['info' => 'gray', 'warning' => 'warning', 'critical' => 'danger'];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            // Big table: the time range filter is always applied so MariaDB
            // reads only the partitions of that period.
            ->deferFilters()
            ->persistFiltersInSession()
            ->columns([
                TextColumn::make('occurred_at')->label('الوقت')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('project.name')->label('المشروع')->toggleable(),
                TextColumn::make('event')->label('الحدث')->searchable()->fontFamily('mono')->weight('medium'),
                TextColumn::make('action')->label('العملية')->badge()->color('gray'),
                TextColumn::make('severity')->label('الخطورة')->badge()
                    ->formatStateUsing(fn (string $state) => self::SEVERITY_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => self::SEVERITY_COLORS[$state] ?? 'gray'),
                // Systems often send only actor type + id: show "user #42"
                // when there is no name, and the type/id under the name.
                TextColumn::make('actor_name')->label('الفاعل')->searchable(['actor_name', 'actor_id'])
                    ->state(fn (AuditLog $record) => $record->actor_name
                        ?? ($record->actor_id !== null ? trim(($record->actor_type ?? '').' #'.$record->actor_id) : null))
                    ->description(fn (AuditLog $record) => $record->actor_name !== null
                        ? trim(($record->actor_type ?? '').' '.($record->actor_id ?? '')) : null)
                    ->placeholder('—'),
                TextColumn::make('subject_id')->label('الكيان')->searchable()
                    ->formatStateUsing(fn (AuditLog $record) => trim(($record->subject_type ?? '').' #'.($record->subject_id ?? ''), ' #'))
                    ->placeholder('—'),
                TextColumn::make('tags')->label('الوسوم')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('external_id')->label('external_id')->searchable()->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uid')->label('UID')->fontFamily('mono')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->label('الفترة')
                    ->schema([
                        DateTimePicker::make('from')->label('من')->default(now()->subDays(7)->startOfDay())->required(),
                        DateTimePicker::make('until')->label('إلى'),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->where('occurred_at', '>=', Carbon::parse($data['from'] ?? now()->subDays(7))->utc())
                        ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->where('occurred_at', '<=', Carbon::parse($until)->utc())))
                    ->indicateUsing(fn (array $data) => 'من '.Carbon::parse($data['from'] ?? now()->subDays(7))->format('Y-m-d').(filled($data['until'] ?? null) ? ' إلى '.Carbon::parse($data['until'])->format('Y-m-d') : '')),

                SelectFilter::make('project_id')
                    ->label('المشروع')
                    ->multiple()
                    ->searchable()
                    ->options(fn () => Project::query()->orderBy('name')->pluck('name', 'id')->all()),

                SelectFilter::make('severity')
                    ->label('الخطورة')
                    ->multiple()
                    ->options(self::SEVERITY_LABELS),

                Filter::make('details')
                    ->label('تفاصيل')
                    ->schema([
                        TextInput::make('event')->label('الحدث (يدعم user.*)'),
                        TextInput::make('action')->label('العملية'),
                        TextInput::make('actor_id')->label('معرّف الفاعل'),
                        TextInput::make('subject_type')->label('نوع الكيان'),
                        TextInput::make('subject_id')->label('معرّف الكيان'),
                        TextInput::make('tag')->label('وسم'),
                        TextInput::make('correlation_id')->label('correlation_id'),
                    ])
                    ->columns(4)
                    ->columnSpanFull()
                    ->query(function (Builder $query, array $data) {
                        foreach (['action', 'actor_id', 'subject_type', 'subject_id', 'correlation_id'] as $field) {
                            if (filled($data[$field] ?? null)) {
                                $query->where($field, $data[$field]);
                            }
                        }
                        if (filled($data['event'] ?? null)) {
                            $event = (string) $data['event'];
                            str_ends_with($event, '*')
                                ? $query->where('event', 'like', str_replace(['%', '_'], ['\%', '\_'], rtrim($event, '*')).'%')
                                : $query->where('event', $event);
                        }
                        if (filled($data['tag'] ?? null)) {
                            $query->whereJsonContains('tags', (string) $data['tag']);
                        }
                    }),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
