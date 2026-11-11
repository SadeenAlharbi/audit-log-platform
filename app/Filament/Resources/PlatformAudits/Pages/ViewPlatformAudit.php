<?php

namespace App\Filament\Resources\PlatformAudits\Pages;

use App\Filament\Resources\PlatformAudits\PlatformAuditResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPlatformAudit extends ViewRecord
{
    protected static string $resource = PlatformAuditResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
