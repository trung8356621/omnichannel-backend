<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\PromptResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\PromptResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrompt extends CreateRecord
{
    protected static string $resource = PromptResource::class;

    /** @var array<int, array<string, mixed>> */
    public array $promptDataVirtual = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->promptDataVirtual = $data['prompt_data'] ?? [];
        unset($data['prompt_data']);

        $data['user_id'] = auth()->id();
        $data['title'] = $data['name'] ?? $data['title'] ?? '';
        $data['variables'] = PromptResource::sanitizeDeclaredVariables($data['variables'] ?? []);

        if (blank($data['model_category'] ?? null) && filled($data['ai_connection_id'] ?? null)) {
            $data['model_category'] = PromptResource::defaultModelCategoryForConnection($data['ai_connection_id']);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncPromptParts();
    }

    protected function syncPromptParts(): void
    {
        foreach ($this->promptDataVirtual as $index => $item) {
            $attributes = PromptResource::partAttributesFromBuilderItem($item, $index);
            if ($attributes === null) {
                continue;
            }

            $this->record->parts()->create($attributes);
        }
    }
}
