<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function afterCreate(): void
    {
        $role = $this->getRecord();
        if ($role instanceof Role) {
            $role->syncPermissions(RoleForm::selectedPermissions($this->data));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
