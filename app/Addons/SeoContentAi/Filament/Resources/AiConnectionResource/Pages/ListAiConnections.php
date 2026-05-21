<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAiConnections extends ListRecords
{
    protected static string $resource = AiConnectionResource::class;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-ai-list';

    public function getTitle(): string
    {
        return '';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Thêm kết nối AI'),
        ];
    }
}
