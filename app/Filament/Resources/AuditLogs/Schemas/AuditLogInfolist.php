<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Filament\Resources\AuditLogs\Tables\AuditLogsTable;
use App\Filament\Support\ValueDiff;
use App\Models\AuditLog;
use App\Services\Security\SensitiveDataMasker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        // Reading order: what happened → who did it, to what → what changed →
        // reference ids. Empty optional fields are hidden instead of showing "—".
        return $schema->components([
            Section::make('الحدث')->columnSpanFull()->columns(4)->schema([
                TextEntry::make('event')->label('الحدث')->fontFamily('mono')->weight('bold')->columnSpan(2),
                TextEntry::make('action')->label('العملية')->badge()
                    ->formatStateUsing(fn (string $state) => AuditLogsTable::ACTION_LABELS[$state] ?? $state),
                TextEntry::make('severity')->label('الخطورة')->badge()
                    ->formatStateUsing(fn (string $state) => AuditLogsTable::SEVERITY_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => AuditLogsTable::SEVERITY_COLORS[$state] ?? 'gray'),
                TextEntry::make('project.name')->label('المشروع'),
                TextEntry::make('occurred_at')->label('وقت الحدث')->dateTime('Y-m-d H:i:s.v')
                    ->hint(fn (AuditLog $record): string => $record->occurred_at->utc()->format('Y-m-d H:i:s.v').' UTC'),
                TextEntry::make('received_at')->label('وقت الاستلام')->dateTime('Y-m-d H:i:s.v')
                    ->hint(fn (AuditLog $record): string => $record->received_at->utc()->format('Y-m-d H:i:s.v').' UTC'),
                TextEntry::make('tags')->label('الوسوم')->badge()
                    ->visible(fn (AuditLog $record): bool => filled($record->tags)),
            ]),

            Section::make('مَن وعلى ماذا')->columnSpanFull()->schema([
                Grid::make(2)->schema([
                    Fieldset::make('منفّذ الإجراء')->columns(2)->schema([
                        TextEntry::make('actor_name')->label('الاسم')->placeholder('—'),
                        TextEntry::make('actor_id')->label('المعرّف')->placeholder('—'),
                        TextEntry::make('actor_type')->label('النوع')->placeholder('—'),
                        TextEntry::make('actor_ip')->label('عنوان IP')->placeholder('—'),
                        TextEntry::make('user_agent')->label('المتصفح أو الجهاز')->columnSpanFull()
                            ->visible(fn (AuditLog $record): bool => filled($record->user_agent)),
                    ]),
                    Fieldset::make('الكيان المتأثر')->columns(2)->schema([
                        TextEntry::make('subject_type')->label('النوع')->placeholder('—'),
                        TextEntry::make('subject_id')->label('المعرّف')->placeholder('—'),
                    ]),
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

            Section::make('المعرّفات المرجعية')->columnSpanFull()->columns(4)->collapsible()->schema([
                TextEntry::make('uid')->label('رقم السجل (UID)')->fontFamily('mono')->copyable()->columnSpan(2),
                TextEntry::make('external_id')->label('المعرّف في النظام المرسل')->fontFamily('mono')->placeholder('—')->copyable()->columnSpan(2),
                TextEntry::make('request_id')->label('request_id')->fontFamily('mono')->columnSpan(2)
                    ->visible(fn (AuditLog $record): bool => filled($record->request_id)),
                TextEntry::make('correlation_id')->label('correlation_id')->fontFamily('mono')->columnSpan(2)
                    ->visible(fn (AuditLog $record): bool => filled($record->correlation_id)),
            ]),

            Section::make('بيانات إضافية (metadata)')
                ->columnSpanFull()
                ->collapsed()
                ->visible(fn (AuditLog $record): bool => filled($record->metadata))
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
