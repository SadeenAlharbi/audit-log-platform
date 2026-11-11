<?php

namespace App\Filament\Resources\SecurityEvents\Schemas;

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
            Section::make()->columns(3)->columnSpanFull()->schema([
                TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i:s.v'),
                TextEntry::make('reason')->label('السبب')->badge()->color('danger'),
                TextEntry::make('ip')->label('IP')->fontFamily('mono')->copyable(),
                TextEntry::make('project.name')->label('المشروع')->placeholder('غير معروف'),
                TextEntry::make('apiKey.name')->label('المفتاح')->placeholder('—'),
                TextEntry::make('apiKey.key_prefix')->label('بادئة المفتاح')->fontFamily('mono')->placeholder('—'),
            ]),
            Section::make('تفاصيل الطلب')->columnSpanFull()->schema([
                KeyValueEntry::make('request_meta')->hiddenLabel()
                    ->state(fn (SecurityEvent $record) => collect($record->request_meta ?? [])->map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v))->all()),
            ]),
        ]);
    }
}
