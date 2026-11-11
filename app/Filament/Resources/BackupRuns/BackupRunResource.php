<?php

namespace App\Filament\Resources\BackupRuns;

use App\Filament\Resources\BackupRuns\Pages\ListBackupRuns;
use App\Filament\Resources\BackupRuns\Schemas\BackupRunInfolist;
use App\Filament\Resources\BackupRuns\Tables\BackupRunsTable;
use App\Models\BackupRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class BackupRunResource extends Resource
{
    protected static ?string $model = BackupRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'التشغيل';

    protected static ?string $modelLabel = 'نسخة احتياطية';

    protected static ?string $pluralModelLabel = 'النسخ الاحتياطي';

    public static function infolist(Schema $schema): Schema
    {
        return BackupRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BackupRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackupRuns::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
