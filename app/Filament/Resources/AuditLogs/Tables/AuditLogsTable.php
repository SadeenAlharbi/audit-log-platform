<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use App\Models\Project;
use App\Support\Time\DisplayTime;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogsTable
{
    /**
     * Arabic names of the common actions; any other value is shown as sent
     * (`action` is free-form in the API).
     *
     * @var array<string, string>
     */
    public const ACTION_LABELS = [
        'create' => 'إنشاء', 'update' => 'تعديل', 'delete' => 'حذف', 'restore' => 'استرجاع',
        'view' => 'عرض', 'export' => 'تصدير', 'import' => 'استيراد', 'login' => 'دخول',
        'logout' => 'خروج', 'approve' => 'اعتماد', 'reject' => 'رفض', 'custom' => 'مخصص',
    ];

    /** @var array<string, string> */
    public const SEVERITY_LABELS = ['info' => 'معلومة', 'warning' => 'تحذير', 'critical' => 'حرج'];

    public const SEVERITY_COLORS = ['info' => 'gray', 'warning' => 'warning', 'critical' => 'danger'];

    /**
     * A searchable list: opens with the recent values, typing searches all.
     *
     * @param  callable(?string, array{from?: mixed, until?: mixed}): array<string, string>  $options
     * @param  (callable(string, array{from?: mixed, until?: mixed}): string)|null  $optionLabel
     */
    private static function listFilter(string $name, string $label, ?string $placeholder, callable $options, ?callable $optionLabel = null): Select
    {
        return Select::make($name)
            ->label($label)
            ->placeholder($placeholder ?? 'الكل')
            ->searchable()
            ->searchPrompt('اكتب للبحث')
            ->noSearchResultsMessage('لا توجد قيمة مطابقة')
            // Limited to the period picked in the "period" filter (not yet
            // applied picks included), so the lists and the search read only
            // those partitions (AuditLogFilterOptions).
            ->options(fn (mixed $livewire) => $options(null, self::pickedPeriod($livewire)))
            ->getSearchResultsUsing(fn (string $search, mixed $livewire) => $options($search, self::pickedPeriod($livewire)))
            ->getOptionLabelUsing(fn ($value, mixed $livewire) => $optionLabel ? $optionLabel((string) $value, self::pickedPeriod($livewire)) : (string) $value);
    }

    /**
     * The period filter's current state (local time), or [] for the default.
     *
     * @return array{from?: mixed, until?: mixed}
     */
    public static function pickedPeriod(mixed $livewire): array
    {
        $state = data_get($livewire, 'tableDeferredFilters.period') ?? data_get($livewire, 'tableFilters.period');
        if (! is_array($state)) {
            return [];
        }

        $period = [];
        if (array_key_exists('from', $state)) {
            $period['from'] = $state['from'];
        }
        if (array_key_exists('until', $state)) {
            $period['until'] = $state['until'];
        }

        return $period;
    }

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
                TextColumn::make('action')->label('العملية')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => self::ACTION_LABELS[$state] ?? $state),
                TextColumn::make('severity')->label('الخطورة')->badge()
                    ->formatStateUsing(fn (string $state) => self::SEVERITY_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => self::SEVERITY_COLORS[$state] ?? 'gray'),
                // Systems often send only actor type + id: show "user #42"
                // when there is no name, and the type/id under the name.
                TextColumn::make('actor_name')->label('منفّذ الإجراء')->searchable(['actor_name', 'actor_id'])
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
                        DateTimePicker::make('from')->label('من')->default(fn () => DisplayTime::now()->subDays(7)->startOfDay())->required()
                            ->native(false)->seconds(false)->displayFormat('Y/m/d  H:i')->firstDayOfWeek(7),
                        DateTimePicker::make('until')->label('إلى')
                            ->native(false)->seconds(false)->displayFormat('Y/m/d  H:i')->firstDayOfWeek(7),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->query(fn (Builder $query, array $data) => $query
                        // The pickers hold local time (DisplayTime); occurred_at is UTC.
                        ->where('occurred_at', '>=', filled($data['from'] ?? null) ? DisplayTime::toUtc($data['from']) : DisplayTime::startOfDayUtc(7))
                        ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->where('occurred_at', '<=', DisplayTime::toUtc($until))))
                    ->indicateUsing(fn (array $data) => 'من '.(filled($data['from'] ?? null) ? DisplayTime::toUtc($data['from']) : DisplayTime::startOfDayUtc(7))->setTimezone(DisplayTime::timezone())->format('Y-m-d H:i')
                        .(filled($data['until'] ?? null) ? ' إلى '.DisplayTime::toUtc($data['until'])->setTimezone(DisplayTime::timezone())->format('Y-m-d H:i') : '')),

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
                    // Lists of the values actually received, searchable by
                    // typing (AuditLogFilterOptions).
                    ->schema([
                        self::listFilter('event', 'الحدث', null,
                            fn (?string $search, array $period) => AuditLogFilterOptions::events($search, $period))
                            ->helperText('القيم المنتهية بـ .* تختار عائلة كاملة، مثل user.*'),
                        self::listFilter('action', 'العملية', null,
                            fn (?string $search, array $period) => AuditLogFilterOptions::actions($search, $period),
                            fn (string $value) => AuditLogFilterOptions::actionLabel($value)),
                        self::listFilter('actor_id', 'منفّذ الإجراء', null,
                            fn (?string $search, array $period) => AuditLogFilterOptions::actors($search, $period),
                            fn (string $value, array $period) => AuditLogFilterOptions::actors($value, $period)[$value] ?? $value),
                        self::listFilter('subject_type', 'نوع الكيان', null,
                            fn (?string $search, array $period) => AuditLogFilterOptions::values('subject_type', $search, $period)),
                        self::listFilter('subject_id', 'معرّف الكيان', null,
                            fn (?string $search, array $period) => AuditLogFilterOptions::values('subject_id', $search, $period)),
                        self::listFilter('tag', 'الوسم', null,
                            fn (?string $search, array $period) => AuditLogFilterOptions::tags($search, $period)),
                        self::listFilter('correlation_id', 'correlation_id', null,
                            fn (?string $search, array $period) => AuditLogFilterOptions::values('correlation_id', $search, $period)),
                    ])
                    ->columns(3)
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
            ], layout: FiltersLayout::Dropdown)
            // The filter button sits beside the search box; the panel opens wide.
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::FourExtraLarge)
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
