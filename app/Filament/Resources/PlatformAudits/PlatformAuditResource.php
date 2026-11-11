<?php

namespace App\Filament\Resources\PlatformAudits;

use App\Filament\Resources\PlatformAudits\Pages\ListPlatformAudits;
use App\Filament\Resources\PlatformAudits\Pages\ViewPlatformAudit;
use App\Filament\Resources\PlatformAudits\Schemas\PlatformAuditInfolist;
use App\Filament\Resources\PlatformAudits\Tables\PlatformAuditsTable;
use App\Models\PlatformAudit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Self-audit trail (plan §13), immutable.
 */
class PlatformAuditResource extends Resource
{
    protected static ?string $model = PlatformAudit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'الأمان';

    protected static ?string $modelLabel = 'عملية إدارية';

    protected static ?string $pluralModelLabel = 'تدقيق المنصة';

    protected static ?string $slug = 'platform-audit';

    public static function infolist(Schema $schema): Schema
    {
        return PlatformAuditInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlatformAuditsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformAudits::route('/'),
            'view' => ViewPlatformAudit::route('/{record}'),
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
