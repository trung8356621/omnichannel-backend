<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\PromptResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\PromptResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrompt extends CreateRecord
{
    protected static string $resource = PromptResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $markdown = trim((string) ($data['markdown_content'] ?? ''));

        $data['user_id'] = auth()->id();
        $data['title'] = $data['name'] ?? $data['title'] ?? '';
        $data['variables'] = PromptResource::mergeVariablesFromMarkdown(
            $markdown,
            $data['variables'] ?? [],
        );

        if (blank($data['model_category'] ?? null) && filled($data['ai_connection_id'] ?? null)) {
            $data['model_category'] = PromptResource::defaultModelCategoryForConnection($data['ai_connection_id']);
        }

        return $data;
    }
}
