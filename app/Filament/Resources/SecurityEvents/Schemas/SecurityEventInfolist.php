<?php

namespace App\Filament\Resources\SecurityEvents\Schemas;

use App\Filament\Support\Labels;
use App\Models\SecurityEvent;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SecurityEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المحاولة')->columns(3)->columnSpanFull()->schema([
                TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i:s.v'),
                TextEntry::make('reason')->label('السبب')->badge()->color('danger')
                    ->formatStateUsing(fn (string $state) => Labels::reason($state)),
                TextEntry::make('ip')->label('عنوان IP')->fontFamily('mono')->copyable(),
                TextEntry::make('project.name')->label('المشروع')->placeholder('غير معروف'),
                TextEntry::make('apiKey.name')->label('المفتاح')->placeholder('—'),
                // Unknown keys have no row: show the prefix that was sent (request_meta).
                TextEntry::make('key_prefix')->label('بادئة المفتاح')->fontFamily('mono')->placeholder('—')
                    ->state(fn (SecurityEvent $record): ?string => $record->apiKey->key_prefix ?? ($record->request_meta['key_prefix'] ?? null)),
            ]),
            Section::make('تفاصيل الطلب')->columnSpanFull()->schema([
                KeyValueEntry::make('request_meta')->label('تفاصيل الطلب')->hiddenLabel()
                    ->keyLabel('البيان')->valueLabel('القيمة')
                    ->state(fn (SecurityEvent $record) => collect($record->request_meta ?? [])
                        ->mapWithKeys(fn ($v, $k) => [(Labels::SECURITY_DETAILS[$k] ?? $k) => "\u{2068}".(is_scalar($v) ? (string) $v : json_encode($v))."\u{2069}"])->all()),
            ]),
        ]);
    }
}
