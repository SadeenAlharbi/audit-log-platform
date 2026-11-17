<?php

namespace App\Filament\Resources\BackupRuns\Schemas;

use App\Models\BackupRun;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class BackupRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('type')->label('النوع')->badge(),
            TextEntry::make('status')->label('الحالة')->badge(),
            TextEntry::make('started_at')->label('البداية')->dateTime('Y-m-d H:i'),
            TextEntry::make('finished_at')->label('النهاية')->dateTime('Y-m-d H:i')->placeholder('—'),
            TextEntry::make('size')->label('الحجم')->formatStateUsing(fn ($state) => $state ? "\u{2066}".Number::fileSize((int) $state)."\u{2069}" : '—'),
            TextEntry::make('checksum')->label('SHA-256')->fontFamily('mono')->placeholder('—'),
            TextEntry::make('location')->label('الموقع')->fontFamily('mono')->columnSpanFull()->placeholder('—'),
            TextEntry::make('error')->label('الخطأ')->color('danger')->columnSpanFull()->placeholder('—'),
            KeyValueEntry::make('meta')->label('تفاصيل')->columnSpanFull()
                ->state(fn (BackupRun $record) => collect($record->meta ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))->all()),
        ]);
    }
}
