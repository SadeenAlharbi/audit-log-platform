<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Filament\Resources\AuditLogs\Tables\AuditLogsTable;
use App\Filament\Support\ValueDiff;
use App\Models\AuditLog;
use App\Services\Security\SensitiveDataMasker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('الحدث')->columnSpan(2)->columns(3)->schema([
                    TextEntry::make('event')->label('الحدث')->fontFamily('mono')->weight('bold'),
                    TextEntry::make('action')->label('العملية')->badge(),
                    TextEntry::make('severity')->label('الخطورة')->badge()
                        ->color(fn (string $state) => AuditLogsTable::SEVERITY_COLORS[$state] ?? 'gray'),
                    TextEntry::make('project.name')->label('المشروع'),
                    TextEntry::make('occurred_at')->label('وقت الحدث (UTC)')->dateTime('Y-m-d H:i:s.v'),
                    TextEntry::make('received_at')->label('وقت الاستلام (UTC)')->dateTime('Y-m-d H:i:s.v'),
                    TextEntry::make('tags')->label('الوسوم')->badge()->placeholder('—')->columnSpanFull(),
                ]),
                Section::make('المعرّفات')->columnSpan(1)->schema([
                    TextEntry::make('uid')->label('UID')->fontFamily('mono')->copyable(),
                    TextEntry::make('external_id')->label('external_id')->fontFamily('mono')->placeholder('—')->copyable(),
                    TextEntry::make('request_id')->label('request_id')->fontFamily('mono')->placeholder('—'),
                    TextEntry::make('correlation_id')->label('correlation_id')->fontFamily('mono')->placeholder('—'),
                ]),
            ]),

            Grid::make(2)->columnSpanFull()->schema([
                Section::make('الفاعل')->columns(2)->schema([
                    TextEntry::make('actor_type')->label('النوع')->placeholder('—'),
                    TextEntry::make('actor_id')->label('المعرّف')->placeholder('—'),
                    TextEntry::make('actor_name')->label('الاسم')->placeholder('—'),
                    TextEntry::make('actor_ip')->label('IP')->placeholder('—'),
                    TextEntry::make('user_agent')->label('User agent')->placeholder('—')->columnSpanFull(),
                ]),
                Section::make('الكيان المتأثر')->columns(2)->schema([
                    TextEntry::make('subject_type')->label('النوع')->placeholder('—'),
                    TextEntry::make('subject_id')->label('المعرّف')->placeholder('—'),
                ]),
            ]),

            Section::make('التغييرات (قبل / بعد)')
                ->columnSpanFull()
                ->schema([
                    View::make('filament.value-diff')->viewData(function (AuditLog $record) {
                        $reveal = auth()->user()?->can('viewSensitive', $record) ?? false;
                        $masker = app(SensitiveDataMasker::class);
                        $old = $reveal ? $record->old_values : $masker->mask($record->old_values);
                        $new = $reveal ? $record->new_values : $masker->mask($record->new_values);

                        return [
                            'rows' => ValueDiff::rows($old, $new),
                            'masked' => ! $reveal && ($old !== $record->old_values || $new !== $record->new_values),
                        ];
                    }),
                ]),

            Section::make('بيانات إضافية (metadata)')
                ->columnSpanFull()
                ->collapsed()
                ->schema([
                    KeyValueEntry::make('metadata')
                        ->hiddenLabel()
                        ->state(function (AuditLog $record) {
                            $reveal = auth()->user()?->can('viewSensitive', $record) ?? false;
                            $data = $reveal ? $record->metadata : app(SensitiveDataMasker::class)->mask($record->metadata);

                            return collect($data ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE))->all();
                        })
                        ->placeholder('—'),
                ]),
        ]);
    }
}
