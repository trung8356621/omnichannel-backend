<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteServiceResource\Pages;

use App\Filament\Resources\SiteServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSiteServices extends ListRecords
{
    protected static string $resource = SiteServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn (): bool => SiteServiceResource::canCreate()),
        ];
    }
}
