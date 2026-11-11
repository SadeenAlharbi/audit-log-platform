<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\IssuedKeyPresenter;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\ApiKeys\ApiKeyManager;
use Filament\Resources\Pages\CreateRecord;

/**
 * Plan §5 Onboarding: creating a project generates its slug and a first
 * API key (write scope, signed). The key is shown once on the next page.
 */
class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['is_active'] = true;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Project $project */
        $project = $this->getRecord();

        $issued = app(ApiKeyManager::class)->create(
            project: $project,
            name: 'Default (production)',
            scopes: [ProjectApiKey::SCOPE_WRITE],
            environment: 'production',
            withSignature: true,
            by: auth()->user(),
        );

        session()->flash('issued_api_key', IssuedKeyPresenter::arguments($issued));
    }

    protected function getRedirectUrl(): string
    {
        return ProjectResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
