<?php

namespace App\Filament\Resources\FrontendProjectResource\Pages;

use App\Filament\Resources\FrontendProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFrontendProject extends EditRecord
{
    protected static string $resource = FrontendProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
