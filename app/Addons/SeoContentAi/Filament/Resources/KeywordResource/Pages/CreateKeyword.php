<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKeyword extends CreateRecord
{
    protected static string $resource = KeywordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return KeywordResource::getUrl('index');
    }
}
