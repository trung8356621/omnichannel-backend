<?php

namespace App\Filament\Resources\FrontendProjectResource\Pages;

use App\Filament\Resources\FrontendProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFrontendProjects extends ListRecords
{
    protected static string $resource = FrontendProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
