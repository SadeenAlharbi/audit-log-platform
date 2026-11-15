<?php

namespace App\Filament\Resources\PlatformAudits\Schemas;

use App\Filament\Support\Labels;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
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
                    ->visible(fn ($record): bool => filled($record->user_agent)),
            ]),
            Section::make('التغييرات')->columnSpanFull()
                ->visible(fn ($record): bool => filled($record->changes))
                ->schema([
                    TextEntry::make('changes')->hiddenLabel()
                        ->state(fn ($record) => $record->changes ? json_encode($record->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null)
                        ->fontFamily('mono')
                        ->extraAttributes(['class' => 'whitespace-pre-wrap', 'dir' => 'ltr'])
                        ->placeholder('—'),
                ]),
        ]);
    }
}
