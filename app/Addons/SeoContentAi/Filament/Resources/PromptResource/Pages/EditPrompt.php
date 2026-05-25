<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\PromptResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\PromptResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPrompt extends EditRecord
{
    protected static string $resource = PromptResource::class;

    /** @var array<int, array<string, mixed>> */
    public array $promptDataVirtual = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $parts = $this->record->parts()->orderBy('position')->get();

        $data['prompt_data'] = $parts->isEmpty()
            ? PromptResource::defaultPromptDataTemplate()
            : $parts
                ->map(static fn ($part): array => PromptResource::builderItemFromPart($part))
                ->values()
                ->all();

        $data['variables'] = PromptResource::sanitizeDeclaredVariables($this->record->variables ?? []);

        if (blank($data['model_category'] ?? null)) {
            $data['model_category'] = filled($data['ai_connection_id'] ?? null)
                ? PromptResource::defaultModelCategoryForConnection($data['ai_connection_id'])
                : \App\Addons\SeoContentAi\Support\AiModelCategory::GEMINI_FLASH;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->promptDataVirtual = $data['prompt_data'] ?? [];
        unset($data['prompt_data']);

        if (isset($data['name'])) {
            $data['title'] = $data['name'];
        }

        $data['variables'] = PromptResource::sanitizeDeclaredVariables($data['variables'] ?? []);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->parts()->delete();

        foreach ($this->promptDataVirtual as $index => $item) {
            $attributes = PromptResource::partAttributesFromBuilderItem($item, $index);
            if ($attributes === null) {
                continue;
            }

            $this->record->parts()->create($attributes);
        }
    }
}
