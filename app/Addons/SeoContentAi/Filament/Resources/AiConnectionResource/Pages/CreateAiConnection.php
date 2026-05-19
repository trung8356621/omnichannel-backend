<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiConnection extends CreateRecord
{
    protected static string $resource = AiConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['is_global'] = $data['is_global'] ?? false;

        return $data;
    }
}
