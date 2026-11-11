<?php

namespace App\Filament\Resources\SecurityEvents;

use App\Filament\Resources\SecurityEvents\Pages\ListSecurityEvents;
use App\Filament\Resources\SecurityEvents\Pages\ViewSecurityEvent;
use App\Filament\Resources\SecurityEvents\Schemas\SecurityEventInfolist;
use App\Filament\Resources\SecurityEvents\Tables\SecurityEventsTable;
use App\Models\SecurityEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SecurityEventResource extends Resource
{
    protected static ?string $model = SecurityEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'الأمان';

    protected static ?string $modelLabel = 'محاولة مرفوضة';

    protected static ?string $pluralModelLabel = 'المحاولات المرفوضة';

    public static function getNavigationBadge(): ?string
    {
        $count = SecurityEvent::query()->where('created_at', '>=', now()->subDay())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function infolist(Schema $schema): Schema
    {
        return SecurityEventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SecurityEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSecurityEvents::route('/'),
            'view' => ViewSecurityEvent::route('/{record}'),
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
