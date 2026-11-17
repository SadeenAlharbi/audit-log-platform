<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Support\Time\DisplayTime;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المشروع')->columnSpanFull()->columns(4)->schema([
                TextEntry::make('name')->label('الاسم')->weight('bold'),
                TextEntry::make('slug')->label('المعرّف (X-Project)')->copyable()->fontFamily('mono'),
                TextEntry::make('retention_days')->label('مدة الاحتفاظ')->suffix(' يوم'),
                TextEntry::make('is_active')->label('الحالة')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'نشط' : 'موقوف')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextEntry::make('description')->label('الوصف')->columnSpanFull()
                    ->visible(fn (Project $record): bool => filled($record->description)),
                TextEntry::make('creator.name')->label('أنشأه')
                    ->visible(fn (Project $record): bool => $record->creator !== null),
                TextEntry::make('archived_at')->label('أُرشف في')->dateTime()
                    ->visible(fn (Project $record): bool => $record->archived_at !== null),
            ]),

            Section::make('الإحصائيات')->columnSpanFull()->columns(3)->schema([
                TextEntry::make('logs_today')
                    ->label('سجلات اليوم')
                    ->size('lg')->weight('bold')
                    ->state(fn (Project $record) => number_format(self::logsToday($record))),
                TextEntry::make('last_received_at')
                    ->label('آخر استلام')
                    ->helperText('آخر طلب كتابة وصل من النظام')
                    ->state(fn (Project $record) => ProjectApiKey::query()->withoutGlobalScopes()
                        ->where('project_id', $record->id)
                        ->whereJsonContains('scopes', ProjectApiKey::SCOPE_WRITE)
                        ->max('last_used_at'))
                    ->dateTime()
                    ->since()
                    ->placeholder('لا يوجد بعد'),
                TextEntry::make('active_keys')
                    ->label('المفاتيح النشطة')
                    ->size('lg')->weight('bold')
                    ->state(fn (Project $record) => $record->apiKeys()->withoutGlobalScopes()->usable()->count()),
            ]),

            Section::make('دليل الربط')
                ->description('كيف يرسل نظامك السجلات إلى المنصة.')
                ->collapsible()
                ->columnSpanFull()
                ->schema([
                    View::make('filament.integration-guide')
                        ->viewData(fn (Project $record) => ['project' => $record]),
                ]),
        ]);
    }

    public static function logsToday(Project $project): int
    {
        return AuditLog::query()
            ->withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('occurred_at', '>=', DisplayTime::startOfDayUtc())
            ->count();
    }
}
