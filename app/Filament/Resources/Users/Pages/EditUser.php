<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** Back to the list after saving. */
    protected function getRedirectUrl(): string
    {
        return UserResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return []; // users are deactivated, never deleted
    }

    protected function afterSave(): void
    {
        $user = $this->getRecord();
        if ($user instanceof User) {
            SyncPlatformRoles::sync($user, $this->data['platform_roles'] ?? null);
        }
    }
}
