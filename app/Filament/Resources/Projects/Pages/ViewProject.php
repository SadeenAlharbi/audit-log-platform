<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Actions\ArchiveProjectAction;
use App\Filament\Resources\Projects\Actions\SendTestLogAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\IssuedKeyPresenter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // A key issued on the previous request is shown once.
        if ($issued = session()->pull('issued_api_key')) {
            $this->mountAction('showIssuedKey', $issued);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            SendTestLogAction::make(),
            ArchiveProjectAction::make(),
        ];
    }

    /** Mounted by name only; never rendered as a button. */
    public function showIssuedKeyAction(): Action
    {
        return IssuedKeyPresenter::action();
    }
}
