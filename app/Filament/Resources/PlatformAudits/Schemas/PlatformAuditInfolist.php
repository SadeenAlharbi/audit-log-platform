<?php

namespace App\Filament\Resources\PlatformAudits\Schemas;

use App\Filament\Support\Labels;
use App\Filament\Support\PlatformAuditChanges;
use App\Models\PlatformAudit;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class PlatformAuditInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('العملية')->columns(3)->columnSpanFull()->schema([
                TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i:s.v'),
                TextEntry::make('user.name')->label('المستخدم')->placeholder('النظام'),
                TextEntry::make('action')->label('العملية')->badge()
                    ->formatStateUsing(fn (string $state) => Labels::action($state)),
                TextEntry::make('target_type')->label('نوع الهدف')->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => Labels::recordType($state)),
                TextEntry::make('target_id')->label('معرّف الهدف')->placeholder('—'),
                TextEntry::make('ip')->label('عنوان IP')->placeholder('—'),
                TextEntry::make('user_agent')->label('المتصفح أو الجهاز')->columnSpanFull()
                    ->formatStateUsing(fn (?string $state): ?string => $state === 'console' ? 'سطر الأوامر (أمر artisan أو مهمة مجدولة)' : $state)
                    ->visible(fn ($record): bool => filled($record->user_agent)),
            ]),
            // Before / after as a table (same view as the audit log diff).
            Section::make('التغييرات (قبل / بعد)')->columnSpanFull()
                ->visible(fn (PlatformAudit $record): bool => PlatformAuditChanges::diffRows($record->action, $record->changes) !== null)
                ->schema([
                    View::make('filament.value-diff')->viewData(fn (PlatformAudit $record): array => [
                        'rows' => PlatformAuditChanges::diffRows($record->action, $record->changes) ?? [],
                        'masked' => false,
                    ]),
                ]),

            // Details of the action, or the full settings of a configuration
            // record (expanded for the baseline, collapsed for a change).
            Section::make(fn (PlatformAudit $record): string => PlatformAuditChanges::isConfig($record->action, $record->changes ?? [])
                ? 'كل الإعدادات المسجلة' : 'التفاصيل')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed(fn (PlatformAudit $record): bool => $record->action === 'config.changed')
                ->visible(fn (PlatformAudit $record): bool => PlatformAuditChanges::isConfig($record->action, $record->changes ?? [])
                    || (PlatformAuditChanges::diffRows($record->action, $record->changes) === null && filled($record->changes)))
                ->schema([
                    KeyValueEntry::make('changes')->label('التفاصيل')->hiddenLabel()
                        ->keyLabel('البند')->valueLabel('القيمة')
                        ->state(fn (PlatformAudit $record): array => PlatformAuditChanges::details($record->action, $record->changes))
                        ->extraAttributes(fn (PlatformAudit $record): array => PlatformAuditChanges::isConfig($record->action, $record->changes ?? []) ? ['dir' => 'ltr'] : []),
                    TextEntry::make('changes.hash')->label('بصمة الإعدادات (SHA-256)')->fontFamily('mono')
                        ->visible(fn (PlatformAudit $record): bool => filled($record->changes['hash'] ?? null)),
                ]),
        ]);
    }
}
