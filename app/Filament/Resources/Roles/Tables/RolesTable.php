<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Filament\Support\Labels;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['permissions', 'users']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('الدور')->weight('bold')
                    ->formatStateUsing(fn (string $state) => Labels::role($state))
                    ->description(fn ($record) => $record->name, position: 'below'),
                TextColumn::make('scope')->label('النطاق')
                    ->formatStateUsing(fn (string $state) => $state === 'platform' ? 'المنصة' : 'مشروع'),
                TextColumn::make('permissions_count')->label('الصلاحيات')->numeric(),
                TextColumn::make('users_count')->label('المستخدمون (على مستوى المنصة)')->numeric(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }
}
