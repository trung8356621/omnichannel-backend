<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSeoProjects extends ListRecords
{
    protected static string $resource = SeoProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
