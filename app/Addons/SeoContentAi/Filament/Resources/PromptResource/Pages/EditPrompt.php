<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\PromptResource\Pages;

use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsOverview;
use App\Addons\SeoContentAi\Filament\Resources\PromptResource;
use App\Addons\SeoContentAi\Services\AiModelsReadinessService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPrompt extends EditRecord
{
    protected static string $resource = PromptResource::class;

    protected function getHeaderActions(): array
    {
        $readiness = app(AiModelsReadinessService::class);
        $record = $this->getRecord();
        $isReady = $readiness->isPromptReady($record);

        return [
            Actions\Action::make('test')
                ->label($isReady ? 'Run test' : 'Sync models')
                ->icon($isReady ? 'heroicon-o-play' : 'heroicon-o-cpu-chip')
                ->color($isReady ? 'success' : 'warning')
                ->url(
                    $isReady
                        ? PromptResource::getUrl('test', ['record' => $record])
                        : SeoSettingsOverview::getUrl(),
                ),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $markdown = trim((string) ($data['markdown_content'] ?? ''));

        $data['variables'] = PromptResource::mergeVariablesFromMarkdown(
            $markdown,
            $this->record->variables ?? [],
        );

        if (blank($data['model_category'] ?? null)) {
            $data['model_category'] = filled($data['ai_connection_id'] ?? null)
                ? PromptResource::defaultModelCategoryForConnection($data['ai_connection_id'])
                : \App\Addons\SeoContentAi\Support\AiModelCategory::GEMINI_FLASH;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $markdown = trim((string) ($data['markdown_content'] ?? ''));

        if (isset($data['name'])) {
            $data['title'] = $data['name'];
        }

        $data['variables'] = PromptResource::mergeVariablesFromMarkdown(
            $markdown,
            $data['variables'] ?? [],
        );

        return $data;
    }
}
