<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAiConnections extends ListRecords
{
    protected static string $resource = AiConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Thêm kết nối AI'),
        ];
    }
}
