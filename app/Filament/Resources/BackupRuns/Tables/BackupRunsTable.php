<?php

namespace App\Filament\Resources\BackupRuns\Tables;

use App\Models\BackupRun;
use Carbon\CarbonInterface;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class BackupRunsTable
{
    public const STATUS_LABELS = ['succeeded' => 'نجحت', 'failed' => 'فشلت', 'running' => 'قيد التنفيذ'];

    public const TYPE_LABELS = [
        'full' => 'كاملة', 'incremental' => 'تزايدية', 'binlog' => 'سجلات القاعدة (binlog)',
        'logs_archive' => 'أرشيف السجلات', 'app_files' => 'ملفات التطبيق',
        'restore_test' => 'اختبار استعادة', 'restore' => 'استعادة',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('started_at')->label('البداية')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('type')->label('النوع')->badge()
                    ->formatStateUsing(fn (string $state) => self::TYPE_LABELS[$state] ?? $state),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => ['succeeded' => 'success', 'failed' => 'danger', 'running' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('duration')->label('المدة')
                    ->state(fn (BackupRun $record) => $record->finished_at
                        ? ($record->started_at->diffInSeconds($record->finished_at) < 1
                            ? 'أقل من ثانية'
                            : $record->started_at->diffForHumans($record->finished_at, CarbonInterface::DIFF_ABSOLUTE))
                        : null)
                    ->placeholder('—'),
                TextColumn::make('size')->label('الحجم')
                    ->formatStateUsing(fn ($state) => $state ? Number::fileSize((int) $state) : '—'),
                TextColumn::make('location')->label('الموقع')->fontFamily('mono')->limit(32)->tooltip(fn ($state) => $state)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('triggeredBy.name')->label('بواسطة')->placeholder('مجدول'),
                TextColumn::make('error')->label('الخطأ')->color('danger')->limit(40)->tooltip(fn ($state) => $state)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')->label('النوع')->options(self::TYPE_LABELS),
                SelectFilter::make('status')->label('الحالة')->options(self::STATUS_LABELS),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }
}
