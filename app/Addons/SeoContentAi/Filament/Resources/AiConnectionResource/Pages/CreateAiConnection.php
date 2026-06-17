<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use App\Addons\SeoContentAi\Filament\Resources\Pages\SeoCreateRecord;
use App\Addons\SeoContentAi\Services\AiModelRouterService;

class CreateAiConnection extends SeoCreateRecord
{
    protected static string $resource = AiConnectionResource::class;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-ai-form';

    public function getTitle(): string
    {
        return __('Add AI connection');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['is_global'] = $data['is_global'] ?? false;
        $data['default_model'] = null;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(AiModelRouterService::class)->syncModelsForConnection((int) $this->record->id);
    }
}
