<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Services\CreateArticlesFromTaskService;
use App\Addons\SeoContentAi\Services\SeoCreateArticleSettingsService;
use App\Addons\SeoContentAi\Services\SeoPromptSettingsOptionsService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Workflows + Editor Media (Prompt|Workflow only). Model routing → SeoSettingsAiAdvanced.
 */
class SeoSettingsWorkflows extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/workflows';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Workflows';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-workflows';

    /** @var array<string, mixed> */
    public array $settingsData = [];

    public function mount(SeoCreateArticleSettingsService $settings): void
    {
        $this->settingsData = $settings->getSettings();
        $this->settingsData[SeoCreateArticleSettingsService::KEY_FEATURED_SNIPPET_PROMPT_ID] =
            $settings->getFeaturedSnippetPromptId();
        $this->form->fill($this->settingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->taskSelect(
                    SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE,
                    __('seo-content-ai::filament.settings_workflows.publish_article'),
                    __('seo-content-ai::filament.settings_workflows.publish_article_hint'),
                ),
                $this->taskSelect(
                    SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE,
                    __('seo-content-ai::filament.settings_workflows.rewrite_article'),
                    __('seo-content-ai::filament.settings_workflows.rewrite_article_hint'),
                ),
                $this->taskSelect(
                    SeoCreateArticleSettingsService::KEY_POST_REVIEW,
                    __('seo-content-ai::filament.settings_workflows.post_review'),
                    __('seo-content-ai::filament.settings_workflows.post_review_hint'),
                ),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_workflows.editor_media_section'))
                    ->description(__('seo-content-ai::filament.settings_workflows.editor_media_description_simple'))
                    ->schema([
                        ...$this->editorMediaSourceFields(
                            sourceKey: SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE,
                            promptKey: SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT,
                            taskKey: SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK,
                            label: __('seo-content-ai::filament.settings_workflows.create_typography_image'),
                            promptOptions: fn (SeoPromptSettingsOptionsService $options): array => $options->activeTypographyImagePromptOptions(),
                            sourceHelper: __('seo-content-ai::filament.settings_workflows.create_typography_image_hint'),
                        ),
                        ...$this->editorMediaSourceFields(
                            sourceKey: SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE,
                            promptKey: SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_IMAGE,
                            taskKey: SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK,
                            label: __('seo-content-ai::filament.settings_workflows.create_product_gallery_image'),
                            promptOptions: fn (SeoPromptSettingsOptionsService $options): array => $options->activeAnyImagePromptOptions(),
                        ),
                        ...$this->editorMediaSourceFields(
                            sourceKey: SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_SOURCE,
                            promptKey: SeoCreateArticleSettingsService::KEY_CREATE_VIDEO,
                            taskKey: SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_TASK,
                            label: __('seo-content-ai::filament.settings_workflows.create_video'),
                            promptOptions: fn (SeoPromptSettingsOptionsService $options): array => $options->activeVideoPromptOptions(),
                            isVideo: true,
                        ),
                    ]),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_workflows.project_keywords_section'))
                    ->description(
                        __('seo-content-ai::filament.settings_workflows.project_keywords_description')
                    )
                    ->schema([
                        Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_PROJECT_KEYWORDS_PROMPT_ID)
                            ->label(__('seo-content-ai::filament.settings_workflows.project_keywords_prompt'))
                            ->options(fn (SeoPromptSettingsOptionsService $options): array => $options->activePromptOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_prompt')),
                    ]),

                Forms\Components\Section::make(__('seo-content-ai::filament.settings_workflows.faq_section'))
                    ->description(__('seo-content-ai::filament.settings_workflows.faq_description'))
                    ->schema([
                        Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_RENEW_FAQ_PROMPT_ID)
                            ->label(__('seo-content-ai::filament.settings_workflows.renew_faq_prompt'))
                            ->options(fn (SeoPromptSettingsOptionsService $options): array => $options->activePromptOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_prompt')),
                    ]),

                Forms\Components\Section::make(__('seo-content-ai::filament.settings_workflows.featured_snippet_section'))
                    ->description(__('seo-content-ai::filament.settings_workflows.featured_snippet_description'))
                    ->schema([
                        Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_FEATURED_SNIPPET_PROMPT_ID)
                            ->label(__('seo-content-ai::filament.settings_workflows.featured_snippet_prompt'))
                            ->helperText(__('seo-content-ai::filament.settings_workflows.featured_snippet_prompt_hint'))
                            ->options(fn (SeoPromptSettingsOptionsService $options): array => $options->activePromptOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_prompt')),
                    ]),

                Forms\Components\Section::make(__('seo-content-ai::filament.settings_workflows.outline_heading_section'))
                    ->description(__('seo-content-ai::filament.settings_workflows.outline_heading_description'))
                    ->schema([
                        Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID)
                            ->label(__('seo-content-ai::filament.settings_workflows.outline_heading_prompt'))
                            ->helperText(__('seo-content-ai::filament.settings_workflows.outline_heading_prompt_hint'))
                            ->options(fn (SeoPromptSettingsOptionsService $options): array => $options->activePromptOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_prompt')),
                    ]),

                Forms\Components\Section::make(__('seo-content-ai::filament.settings_workflows.translate_article_section'))
                    ->description(__('seo-content-ai::filament.settings_workflows.translate_article_description'))
                    ->schema([
                        Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_TRANSLATE_ARTICLE_PROMPT_ID)
                            ->label(__('seo-content-ai::filament.settings_workflows.translate_article_prompt'))
                            ->helperText(__('seo-content-ai::filament.settings_workflows.translate_article_prompt_hint'))
                            ->options(fn (SeoPromptSettingsOptionsService $options): array => $options->activePromptOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_prompt')),
                    ]),

                Forms\Components\Section::make(__('seo-content-ai::filament.settings_workflows.prompt_hooks_section'))
                    ->description(__('seo-content-ai::filament.settings_workflows.prompt_hooks_description'))
                    ->schema([
                        Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_ARTICLE_TITLE_SUGGESTION_PROMPT_ID)
                            ->label(__('seo-content-ai::filament.settings_workflows.article_title_suggestion_prompt'))
                            ->helperText(__('seo-content-ai::filament.settings_workflows.article_title_suggestion_prompt_hint'))
                            ->options(fn (SeoPromptSettingsOptionsService $options): array => $options->activePromptOptionsForHook(
                                'article.title_suggestion',
                            ))
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_prompt')),
                        Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_ARTICLE_META_DESCRIPTION_SUGGESTION_PROMPT_ID)
                            ->label(__('seo-content-ai::filament.settings_workflows.article_meta_description_suggestion_prompt'))
                            ->helperText(__('seo-content-ai::filament.settings_workflows.article_meta_description_suggestion_prompt_hint'))
                            ->options(fn (SeoPromptSettingsOptionsService $options): array => $options->activePromptOptionsForHook(
                                'article.meta_description_suggestion',
                            ))
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_prompt')),
                    ]),
            ])
            ->statePath('settingsData');
    }

    private function taskSelect(string $field, string $label, string $helperText): Forms\Components\Select
    {
        return Forms\Components\Select::make($field)
            ->label($label)
            ->options(fn (CreateArticlesFromTaskService $service): array => $service->taskOptionsForSettings())
            ->searchable()
            ->native(false)
            ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_workflow'))
            ->helperText($helperText);
    }

    /**
     * @param  callable(SeoPromptSettingsOptionsService): array<int, string>  $promptOptions
     * @return list<Forms\Components\Component>
     */
    private function editorMediaSourceFields(
        string $sourceKey,
        string $promptKey,
        string $taskKey,
        string $label,
        callable $promptOptions,
        bool $isVideo = false,
        ?string $sourceHelper = null,
    ): array {
        $radio = Forms\Components\Radio::make($sourceKey)
            ->label($label)
            ->options([
                SeoCreateArticleSettingsService::SOURCE_PROMPT => __('seo-content-ai::filament.settings_workflows.source_prompt'),
                SeoCreateArticleSettingsService::SOURCE_WORKFLOW => __('seo-content-ai::filament.settings_workflows.source_workflow'),
            ])
            ->inline()
            ->live();

        if ($sourceHelper !== null && $sourceHelper !== '') {
            $radio = $radio->helperText($sourceHelper);
        }

        return [
            $radio,
            Forms\Components\Select::make($promptKey)
                ->label(__('seo-content-ai::filament.settings_workflows.choose_prompt'))
                ->options($promptOptions)
                ->searchable()
                ->native(false)
                ->placeholder($isVideo
                    ? __('seo-content-ai::filament.settings_workflows.choose_video_prompt')
                    : __('seo-content-ai::filament.settings_workflows.choose_image_prompt'))
                ->visible(fn (Get $get): bool => ($get($sourceKey) ?? SeoCreateArticleSettingsService::SOURCE_PROMPT)
                    === SeoCreateArticleSettingsService::SOURCE_PROMPT),
            Forms\Components\Select::make($taskKey)
                ->label(__('seo-content-ai::filament.settings_workflows.choose_workflow'))
                ->options(fn (CreateArticlesFromTaskService $service): array => $service->taskOptionsForSettings())
                ->searchable()
                ->native(false)
                ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_workflow'))
                ->helperText(__('seo-content-ai::filament.settings_workflows.workflow_extract_hint'))
                ->visible(fn (Get $get): bool => ($get($sourceKey) ?? '')
                    === SeoCreateArticleSettingsService::SOURCE_WORKFLOW),
        ];
    }

    public function saveSettings(SeoCreateArticleSettingsService $settings): void
    {
        $data = $this->form->getState();

        // Chỉ patch Editor Media + task prompts — không đụng AI Advanced keys.
        $settings->saveSettings([
            SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE => $data[SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE] ?? null,
            SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE => $data[SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE] ?? null,
            SeoCreateArticleSettingsService::KEY_POST_REVIEW => $data[SeoCreateArticleSettingsService::KEY_POST_REVIEW] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE => $data[SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_IMAGE => $data[SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_IMAGE] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK => $data[SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE => $data[SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT => $data[SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK => $data[SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_SOURCE => $data[SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_SOURCE] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_VIDEO => $data[SeoCreateArticleSettingsService::KEY_CREATE_VIDEO] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_TASK => $data[SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_TASK] ?? null,
            SeoCreateArticleSettingsService::KEY_RENEW_FAQ_PROMPT_ID => $data[SeoCreateArticleSettingsService::KEY_RENEW_FAQ_PROMPT_ID] ?? null,
            SeoCreateArticleSettingsService::KEY_PROJECT_KEYWORDS_PROMPT_ID => $data[SeoCreateArticleSettingsService::KEY_PROJECT_KEYWORDS_PROMPT_ID] ?? null,
            SeoCreateArticleSettingsService::KEY_FEATURED_SNIPPET_PROMPT_ID => $data[SeoCreateArticleSettingsService::KEY_FEATURED_SNIPPET_PROMPT_ID] ?? null,
            SeoCreateArticleSettingsService::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID => $data[SeoCreateArticleSettingsService::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID] ?? null,
            SeoCreateArticleSettingsService::KEY_TRANSLATE_ARTICLE_PROMPT_ID => $data[SeoCreateArticleSettingsService::KEY_TRANSLATE_ARTICLE_PROMPT_ID] ?? null,
            SeoCreateArticleSettingsService::KEY_ARTICLE_TITLE_SUGGESTION_PROMPT_ID => $data[SeoCreateArticleSettingsService::KEY_ARTICLE_TITLE_SUGGESTION_PROMPT_ID] ?? null,
            SeoCreateArticleSettingsService::KEY_ARTICLE_META_DESCRIPTION_SUGGESTION_PROMPT_ID => $data[SeoCreateArticleSettingsService::KEY_ARTICLE_META_DESCRIPTION_SUGGESTION_PROMPT_ID] ?? null,
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_workflows.saved'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
