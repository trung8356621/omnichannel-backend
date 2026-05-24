<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKeywords extends ListRecords
{
    protected static string $resource = KeywordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Thêm keyword')
                ->icon('heroicon-o-plus'),
        ];
    }
}
