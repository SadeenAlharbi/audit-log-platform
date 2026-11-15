<?php

namespace App\Filament\Resources\PlatformAudits\Tables;

use App\Filament\Support\Labels;
use App\Models\PlatformAudit;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PlatformAuditsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('user.name')->label('المستخدم')->placeholder('النظام'),
                TextColumn::make('action')->label('العملية')->badge()->searchable()
                    ->formatStateUsing(fn (string $state) => Labels::action($state)),
                TextColumn::make('target_type')->label('الهدف')
                    ->formatStateUsing(fn (PlatformAudit $record) => trim(Labels::recordType($record->target_type).' #'.$record->target_id, ' #'))
                    ->placeholder('—'),
                TextColumn::make('ip')->label('عنوان IP')->fontFamily('mono')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('action')->label('العملية')->multiple()->searchable()
                    ->options(fn () => PlatformAudit::query()->distinct()->orderBy('action')->pluck('action')->mapWithKeys(fn (string $a) => [$a => Labels::action($a)])->all()),
                SelectFilter::make('user_id')->label('المستخدم')->relationship('user', 'name')->searchable(),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }
}
