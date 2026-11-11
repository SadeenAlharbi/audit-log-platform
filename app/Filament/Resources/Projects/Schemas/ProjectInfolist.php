<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\AuditLog;
use App\Models\Project;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('المشروع')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')->label('الاسم'),
                        TextEntry::make('slug')->label('المعرّف (X-Project)')->copyable()->fontFamily('mono'),
                        TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('retention_days')->label('مدة الاحتفاظ')->suffix(' يوم'),
                        IconEntry::make('is_active')->label('نشط')->boolean(),
                        TextEntry::make('creator.name')->label('أنشأه')->placeholder('—'),
                        TextEntry::make('archived_at')->label('أُرشف في')->dateTime()->placeholder('—'),
                    ]),

                Section::make('إحصائيات')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('logs_today')
                            ->label('سجلات اليوم')
                            ->state(fn (Project $record) => number_format(self::logsToday($record))),
                        TextEntry::make('last_event_at')
                            ->label('آخر حدث مستلم')
                            ->state(fn (Project $record) => AuditLog::query()->withoutGlobalScopes()->where('project_id', $record->id)->max('occurred_at'))
                            ->dateTime()
                            ->since()
                            ->placeholder('لا يوجد بعد'),
                        TextEntry::make('active_keys')
                            ->label('المفاتيح النشطة')
                            ->state(fn (Project $record) => $record->apiKeys()->withoutGlobalScopes()->usable()->count()),
                    ]),
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
            ->where('occurred_at', '>=', now()->utc()->startOfDay())
            ->count();
    }
}
