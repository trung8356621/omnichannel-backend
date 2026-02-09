<?php

namespace App\Filament\Resources\SiteServiceResource\Pages;

use App\Filament\Resources\SiteServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSiteService extends EditRecord
{
    protected static string $resource = SiteServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
