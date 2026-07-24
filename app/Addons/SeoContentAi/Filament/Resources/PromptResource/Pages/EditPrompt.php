<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\PromptResource\Pages;

use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsOverview;
use App\Addons\SeoContentAi\Filament\Resources\Pages\SeoEditRecord;
use App\Addons\SeoContentAi\Filament\Resources\PromptResource;
use App\Addons\SeoContentAi\PromptHooks\Exceptions\VersionNotFound;
use App\Addons\SeoContentAi\PromptHooks\PromptHookFormSchema;
use App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookEditorCatalog;
use App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use App\Addons\SeoContentAi\Services\AiModelsReadinessService;
use App\Addons\SeoContentAi\Support\ImageToolType;
use App\Addons\SeoContentAi\Support\PromptPostProcessing;
use Filament\Actions;

class EditPrompt extends SeoEditRecord
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

        // Field model_category đã gỡ khỏi form — không đẩy vào state (tránh clear cột khi save).
        unset($data['model_category']);

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $data['settings'] = PromptPostProcessing::mergeIntoSettings(
            $settings,
            is_array($settings['post_processing'] ?? null) ? $settings['post_processing'] : [],
        );

        $hookKey = trim((string) ($data['hook_key'] ?? ''));
        if ($hookKey !== '') {
            try {
                // Canonical catalog (semver 0.1.0) — không dùng legacy registry (version int 1).
                // Legacy version trên form làm settingsFields rỗng + normalizeForSave ném VersionNotFound (save fail, không toast).
                $catalog = app(PromptHookEditorCatalog::class);
                $storedVersion = trim((string) ($data['hook_version'] ?? ''));
                try {
                    $definition = $storedVersion !== ''
                        ? $catalog->find($hookKey, $storedVersion)
                        : $catalog->latestPinnedOrFail($hookKey);
                } catch (VersionNotFound) {
                    $definition = $catalog->latestPinnedOrFail($hookKey);
                }
                $data['hook_version'] = $definition->version->toString();
                $resolved = app(PromptHookRuntimeSettingsResolver::class)
                    ->resolve(
                        $definition,
                        is_array($data['hook_settings'] ?? null) ? $data['hook_settings'] : [],
                        [],
                    );
                $data['hook_settings'] = $resolved['hook'];
            } catch (\Throwable) {
                // Hook manifest thiếu / đổi key — giữ raw state.
            }
        } else {
            $data['hook_key'] = null;
            $data['hook_version'] = null;
            $data['hook_settings'] = null;
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

        // Giữ giá trị DB cũ — không cập nhật từ form.
        unset($data['model_category']);

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $postProcessing = is_array($settings['post_processing'] ?? null) ? $settings['post_processing'] : [];

        if (! ImageToolType::fromMixed($data['tools'] ?? 'default')->isImagePipeline()
            && $postProcessing === []) {
            $existingSettings = is_array($this->record->settings ?? null) ? $this->record->settings : [];
            $postProcessing = is_array($existingSettings['post_processing'] ?? null)
                ? $existingSettings['post_processing']
                : [];
        }

        $data['settings'] = PromptPostProcessing::mergeIntoSettings(
            $settings,
            $postProcessing,
            (int) ($this->record->id ?? 0) ?: null,
        );

        return PromptHookFormSchema::normalizeForSave($data);
    }
}
