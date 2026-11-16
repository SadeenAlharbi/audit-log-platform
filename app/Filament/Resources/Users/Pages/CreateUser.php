<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** Only "add" and "cancel": no "add and start another" button. */
    protected static bool $canCreateAnother = false;

    /** Back to the list after saving. */
    protected function getRedirectUrl(): string
    {
        return UserResource::getUrl('index');
    }

    protected function afterCreate(): void
    {
        $user = $this->getRecord();
        if ($user instanceof User) {
            SyncPlatformRoles::sync($user, $this->data['platform_roles'] ?? null);
        }
    }
}
