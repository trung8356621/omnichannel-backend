<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeoDatabaseConnectionResource\Pages;

use App\Filament\Resources\SeoDatabaseConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSeoDatabaseConnections extends ListRecords
{
    protected static string $resource = SeoDatabaseConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
