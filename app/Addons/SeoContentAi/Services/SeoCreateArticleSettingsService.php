<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Support\GoogleAiModelRegistry;
use App\Addons\SeoContentAi\Support\GeminiModelVersionPolicy;
use App\Addons\SeoContentAi\Support\RenderingPreference;
use App\Addons\SeoContentAi\Support\TypographyValidationLevel;
use App\Models\WpOption;

final class SeoCreateArticleSettingsService
{
    public const OPTION_KEY = 'seo_create_article_task';

    public const KEY_PUBLISH_ARTICLE = 'publish_article_task_id';

    public const KEY_REWRITE_ARTICLE = 'rewrite_article_task_id';

    public const KEY_POST_REVIEW = 'post_review_task_id';

    public const KEY_CREATE_IMAGE = 'create_image_task_id';

    /** @deprecated Đọc để tương thích wp_options cũ (trước khi chuyển sang task workflow) */
    public const KEY_LEGACY_CREATE_IMAGE_PROMPT = 'create_image_prompt_id';

    public const KEY_CREATE_PRODUCT_GALLERY_IMAGE = 'create_product_gallery_image_prompt_id';

    public const KEY_CREATE_PRODUCT_GALLERY_TASK = 'create_product_gallery_image_task_id';

    public const KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT = 'create_typography_image_prompt_id';

    public const KEY_CREATE_TYPOGRAPHY_IMAGE_TASK = 'create_typography_image_task_id';

    public const KEY_CREATE_VIDEO = 'create_video_prompt_id';

    public const KEY_CREATE_VIDEO_TASK = 'create_video_task_id';

    /** prompt | workflow — một nguồn duy nhất sau migrate-on-load */
    public const KEY_CREATE_IMAGE_SOURCE = 'create_image_source';

    public const KEY_CREATE_PRODUCT_GALLERY_SOURCE = 'create_product_gallery_source';

    public const KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE = 'create_typography_image_source';

    public const KEY_CREATE_VIDEO_SOURCE = 'create_video_source';

    public const SOURCE_PROMPT = 'prompt';

    public const SOURCE_WORKFLOW = 'workflow';

    public const KEY_RENEW_FAQ_PROMPT_ID = 'renew_faq_prompt_id';

    public const KEY_PROJECT_KEYWORDS_PROMPT_ID = 'project_keywords_prompt_id';

    /** Prompt sinh Featured Snippet trên editor bài viết (biến {{input}} = từ khóa chính). */
    public const KEY_FEATURED_SNIPPET_PROMPT_ID = 'featured_snippet_prompt_id';

    /** Prompt tái sinh heading từ tab Outline (nút AI gen). */
    public const KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID = 'outline_heading_regenerator_prompt_id';

    /** Prompt dịch bài viết (nút Dịch nhanh trên bản dịch liên kết). */
    public const KEY_TRANSLATE_ARTICLE_PROMPT_ID = 'translate_article_prompt_id';

    /** Prompt Hook: gợi ý tiêu đề bài viết (article.title_suggestion). */
    public const KEY_ARTICLE_TITLE_SUGGESTION_PROMPT_ID = 'article_title_suggestion_prompt_id';

    /** Prompt Hook: gợi ý thẻ mô tả SEO (article.meta_description_suggestion). */
    public const KEY_ARTICLE_META_DESCRIPTION_SUGGESTION_PROMPT_ID = 'article_meta_description_suggestion_prompt_id';

    /** Thứ tự ưu tiên model sinh ảnh thường (General Image) — AI Advanced. */
    public const KEY_IMAGE_MODEL_PRIORITY = 'image_model_priority';

    /** Priority Typography — migrate-on-load từ image_model_priority nếu trống. */
    public const KEY_TYPOGRAPHY_MODEL_PRIORITY = 'typography_model_priority';

    /** Priority Video (Veo) — AI Advanced; runtime video async chưa đổi. */
    public const KEY_VIDEO_MODEL_PRIORITY = 'video_model_priority';

    /** cost_first | balanced | quality_first — preference khách (AI Advanced). */
    public const KEY_RENDERING_PREFERENCE = 'rendering_preference';

    /** list slug Unknown admin bật thủ công cho routing. */
    public const KEY_ADMIN_ENABLED_UNKNOWN_IMAGE_MODELS = 'admin_enabled_unknown_image_models';

    /** Bật kiểm tra chữ typography sau render. */
    public const KEY_TYPOGRAPHY_VALIDATION_ENABLED = 'typography_validation_enabled';

    /** fast | balanced | strict — mức kiểm tra typography. */
    public const KEY_TYPOGRAPHY_VALIDATION_LEVEL = 'typography_validation_level';

    /** Advanced: candidate count tối đa (1..3). */
    public const KEY_TYPOGRAPHY_MAX_CANDIDATES = 'typography_max_candidates';

    /** Advanced: ngưỡng pass validation 0..1. */
    public const KEY_TYPOGRAPHY_PASS_THRESHOLD = 'typography_pass_threshold';

    /** Advanced: cho phép fallback General Image Priority khi thiếu typography_supported. */
    public const KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK = 'typography_allow_general_image_fallback';

    /** Preferred Vision validation model (text + image_input, Gemini >= 3). */
    public const KEY_TYPOGRAPHY_VALIDATION_MODEL = 'typography_validation_model';

    /** Internal keys — metadata editor job, không phải settings form. */
    public const EDITOR_VAR_MEDIA_SOURCE = '_editor_media_source';

    public const EDITOR_VAR_WORKFLOW_TASK_ID = '_editor_workflow_task_id';

    public const EDITOR_VAR_MEDIA_TARGET = '_editor_media_target';

    /** @deprecated Dùng publish_article_task_id; vẫn đọc/ghi để tương thích wp_options cũ */
    public const KEY_LEGACY_TASK_ID = 'task_id';

    /**
     * @return array{
     *     publish_article_task_id: ?int,
     *     post_review_task_id: ?int,
     *     create_image_task_id: ?int,
     *     create_video_task_id: ?int,
     *     renew_faq_prompt_id: ?int,
     *     project_keywords_prompt_id: ?int,
     * }
     */
    public function getSettings(): array
    {
        $data = WpOption::get(self::OPTION_KEY, []);

        if (! is_array($data)) {
            return $this->emptySettings();
        }

        $publish = $this->positiveIntOrNull($data[self::KEY_PUBLISH_ARTICLE] ?? null)
            ?? $this->positiveIntOrNull($data[self::KEY_LEGACY_TASK_ID] ?? null);

        $createImageTask = $this->positiveIntOrNull($data[self::KEY_CREATE_IMAGE] ?? null);
        $createImagePrompt = $this->positiveIntOrNull($data[self::KEY_LEGACY_CREATE_IMAGE_PROMPT] ?? null);
        $galleryPrompt = $this->positiveIntOrNull($data[self::KEY_CREATE_PRODUCT_GALLERY_IMAGE] ?? null);
        $galleryTask = $this->positiveIntOrNull($data[self::KEY_CREATE_PRODUCT_GALLERY_TASK] ?? null);
        $typographyPrompt = $this->positiveIntOrNull($data[self::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT] ?? null);
        $typographyTask = $this->positiveIntOrNull($data[self::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK] ?? null);
        $videoPrompt = $this->positiveIntOrNull($data[self::KEY_CREATE_VIDEO] ?? null);
        $videoTask = $this->positiveIntOrNull($data[self::KEY_CREATE_VIDEO_TASK] ?? null);

        return [
            self::KEY_PUBLISH_ARTICLE => $publish,
            self::KEY_REWRITE_ARTICLE => $this->positiveIntOrNull($data[self::KEY_REWRITE_ARTICLE] ?? null),
            self::KEY_POST_REVIEW => $this->positiveIntOrNull($data[self::KEY_POST_REVIEW] ?? null),
            self::KEY_CREATE_IMAGE => $createImageTask,
            self::KEY_LEGACY_CREATE_IMAGE_PROMPT => $createImagePrompt,
            self::KEY_CREATE_PRODUCT_GALLERY_IMAGE => $galleryPrompt,
            self::KEY_CREATE_PRODUCT_GALLERY_TASK => $galleryTask,
            self::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT => $typographyPrompt,
            self::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK => $typographyTask,
            self::KEY_CREATE_VIDEO => $videoPrompt,
            self::KEY_CREATE_VIDEO_TASK => $videoTask,
            self::KEY_CREATE_IMAGE_SOURCE => $this->resolveSource(
                $data[self::KEY_CREATE_IMAGE_SOURCE] ?? null,
                $createImageTask,
                $createImagePrompt,
            ),
            self::KEY_CREATE_PRODUCT_GALLERY_SOURCE => $this->resolveSource(
                $data[self::KEY_CREATE_PRODUCT_GALLERY_SOURCE] ?? null,
                $galleryTask,
                $galleryPrompt,
            ),
            self::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE => $this->resolveSource(
                $data[self::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE] ?? null,
                $typographyTask,
                $typographyPrompt,
            ),
            self::KEY_CREATE_VIDEO_SOURCE => $this->resolveSource(
                $data[self::KEY_CREATE_VIDEO_SOURCE] ?? null,
                $videoTask,
                $videoPrompt,
            ),
            self::KEY_RENEW_FAQ_PROMPT_ID => $this->positiveIntOrNull($data[self::KEY_RENEW_FAQ_PROMPT_ID] ?? null),
            self::KEY_PROJECT_KEYWORDS_PROMPT_ID => $this->positiveIntOrNull($data[self::KEY_PROJECT_KEYWORDS_PROMPT_ID] ?? null),
            self::KEY_FEATURED_SNIPPET_PROMPT_ID => $this->positiveIntOrNull($data[self::KEY_FEATURED_SNIPPET_PROMPT_ID] ?? null),
            self::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID => $this->positiveIntOrNull(
                $data[self::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID] ?? null,
            ),
            self::KEY_TRANSLATE_ARTICLE_PROMPT_ID => $this->positiveIntOrNull(
                $data[self::KEY_TRANSLATE_ARTICLE_PROMPT_ID] ?? null,
            ),
            self::KEY_ARTICLE_TITLE_SUGGESTION_PROMPT_ID => $this->positiveIntOrNull(
                $data[self::KEY_ARTICLE_TITLE_SUGGESTION_PROMPT_ID] ?? null,
            ),
            self::KEY_ARTICLE_META_DESCRIPTION_SUGGESTION_PROMPT_ID => $this->positiveIntOrNull(
                $data[self::KEY_ARTICLE_META_DESCRIPTION_SUGGESTION_PROMPT_ID] ?? null,
            ),
            self::KEY_IMAGE_MODEL_PRIORITY => $this->normalizeImageModelPriorityForForm(
                $data[self::KEY_IMAGE_MODEL_PRIORITY] ?? null,
            ),
            self::KEY_TYPOGRAPHY_MODEL_PRIORITY => $this->normalizeImageModelPriorityForForm(
                $this->resolveTypographyPriorityStored($data),
            ),
            self::KEY_VIDEO_MODEL_PRIORITY => $this->normalizeVideoModelPriorityForForm(
                $data[self::KEY_VIDEO_MODEL_PRIORITY] ?? null,
            ),
            self::KEY_RENDERING_PREFERENCE => RenderingPreference::fromMixed(
                $data[self::KEY_RENDERING_PREFERENCE] ?? null,
            )->value,
            self::KEY_ADMIN_ENABLED_UNKNOWN_IMAGE_MODELS => $this->normalizeSlugList(
                $data[self::KEY_ADMIN_ENABLED_UNKNOWN_IMAGE_MODELS] ?? null,
            ),
            self::KEY_TYPOGRAPHY_VALIDATION_ENABLED => (bool) ($data[self::KEY_TYPOGRAPHY_VALIDATION_ENABLED] ?? true),
            self::KEY_TYPOGRAPHY_VALIDATION_LEVEL => TypographyValidationLevel::fromMixed(
                $data[self::KEY_TYPOGRAPHY_VALIDATION_LEVEL] ?? null,
            )->value,
            self::KEY_TYPOGRAPHY_MAX_CANDIDATES => $this->boundedIntOrNull($data[self::KEY_TYPOGRAPHY_MAX_CANDIDATES] ?? null, 1, 3),
            self::KEY_TYPOGRAPHY_PASS_THRESHOLD => $this->boundedFloatOrNull($data[self::KEY_TYPOGRAPHY_PASS_THRESHOLD] ?? null, 0.0, 1.0),
            self::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK => (bool) ($data[self::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK] ?? false),
            self::KEY_TYPOGRAPHY_VALIDATION_MODEL => $this->normalizeOptionalModelSlug(
                $data[self::KEY_TYPOGRAPHY_VALIDATION_MODEL] ?? null,
            ),
        ];
    }

    public function isTypographyValidationEnabled(): bool
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return true;
        }

        return (bool) ($raw[self::KEY_TYPOGRAPHY_VALIDATION_ENABLED] ?? true);
    }

    public function getTypographyValidationLevel(): TypographyValidationLevel
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return TypographyValidationLevel::Balanced;
        }

        return TypographyValidationLevel::fromMixed($raw[self::KEY_TYPOGRAPHY_VALIDATION_LEVEL] ?? null);
    }

    public function getTypographyMaxCandidates(): ?int
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return null;
        }

        return $this->boundedIntOrNull($raw[self::KEY_TYPOGRAPHY_MAX_CANDIDATES] ?? null, 1, 3);
    }

    public function getTypographyPassThreshold(): ?float
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return null;
        }

        return $this->boundedFloatOrNull($raw[self::KEY_TYPOGRAPHY_PASS_THRESHOLD] ?? null, 0.0, 1.0);
    }

    public function allowTypographyGeneralImageFallback(): bool
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return false;
        }

        return (bool) ($raw[self::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK] ?? false);
    }

    public function getTypographyValidationModel(): ?string
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return null;
        }

        return $this->normalizeOptionalModelSlug($raw[self::KEY_TYPOGRAPHY_VALIDATION_MODEL] ?? null);
    }

    /**
     * Typography priority — trống thì migrate sang General Image Priority.
     *
     * @return list<string>
     */
    public function getTypographyModelPriority(): array
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return $this->getImageModelPriority();
        }

        $stored = $raw[self::KEY_TYPOGRAPHY_MODEL_PRIORITY] ?? null;
        if (! is_array($stored) || $stored === []) {
            return $this->getImageModelPriority();
        }

        return $this->normalizeImageModelPriorityList($stored);
    }

    /**
     * @return list<string>
     */
    public function getVideoModelPriority(): array
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return self::defaultVideoModelPriority();
        }

        return $this->normalizeVideoModelPriorityList($raw[self::KEY_VIDEO_MODEL_PRIORITY] ?? null);
    }

    private function boundedIntOrNull(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;
        if ($int < $min || $int > $max) {
            return null;
        }

        return $int;
    }

    private function boundedFloatOrNull(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;
        if ($float < $min || $float > $max) {
            return null;
        }

        return round($float, 4);
    }

    public function getRenderingPreference(): RenderingPreference
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return RenderingPreference::Balanced;
        }

        return RenderingPreference::fromMixed($raw[self::KEY_RENDERING_PREFERENCE] ?? null);
    }

    /**
     * @return list<string>
     */
    public function getAdminEnabledUnknownImageModels(): array
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return [];
        }

        return $this->normalizeSlugList($raw[self::KEY_ADMIN_ENABLED_UNKNOWN_IMAGE_MODELS] ?? null);
    }

    /**
     * @return list<string>
     */
    public function getImageModelPriority(): array
    {
        $raw = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return self::defaultImageModelPriority();
        }

        return $this->normalizeImageModelPriorityList($raw[self::KEY_IMAGE_MODEL_PRIORITY] ?? null);
    }

    /**
     * @return list<string>
     */
    public static function defaultImageModelPriority(): array
    {
        return GoogleAiModelRegistry::defaultImageModelPriority();
    }

    /**
     * @return list<string>
     */
    public static function defaultVideoModelPriority(): array
    {
        return [
            'veo-3.1-fast-generate-preview',
            'veo-3.1-generate-preview',
        ];
    }

    /**
     * @return list<array{slug: string}>
     */
    public function normalizeImageModelPriorityForForm(mixed $stored): array
    {
        $slugs = is_array($stored)
            ? $this->normalizeImageModelPriorityList($stored)
            : self::defaultImageModelPriority();

        return array_map(
            static fn (string $slug): array => ['slug' => $slug],
            $slugs,
        );
    }

    /**
     * @return list<array{slug: string}>
     */
    public function normalizeVideoModelPriorityForForm(mixed $stored): array
    {
        $slugs = is_array($stored)
            ? $this->normalizeVideoModelPriorityList($stored)
            : self::defaultVideoModelPriority();

        return array_map(
            static fn (string $slug): array => ['slug' => $slug],
            $slugs,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveTypographyPriorityStored(array $data): mixed
    {
        $typography = $data[self::KEY_TYPOGRAPHY_MODEL_PRIORITY] ?? null;
        if (is_array($typography) && $typography !== []) {
            return $typography;
        }

        return $data[self::KEY_IMAGE_MODEL_PRIORITY] ?? null;
    }

    private function normalizeOptionalModelSlug(mixed $value): ?string
    {
        $slug = GoogleAiModelRegistry::normalizeSlug(trim((string) ($value ?? '')));

        return $slug !== '' ? $slug : null;
    }

    /**
     * @return list<string>
     */
    private function normalizeImageModelPriorityList(mixed $list): array
    {
        if (! is_array($list) || $list === []) {
            return self::defaultImageModelPriority();
        }

        $normalized = [];

        foreach ($list as $item) {
            $slug = is_string($item)
                ? trim($item)
                : trim((string) (is_array($item) ? ($item['slug'] ?? '') : ''));

            if ($slug === '') {
                continue;
            }

            $slug = GoogleAiModelRegistry::normalizeSlug($slug);
            if ($slug === '') {
                continue;
            }

            // Legacy Gemini major < 3: bỏ khỏi runtime priority (không crash).
            if (! GeminiModelVersionPolicy::isEligibleForAutoRouting($slug)) {
                continue;
            }

            $normalized[] = $slug;
        }

        $normalized = array_values(array_unique(array_filter($normalized)));

        return $normalized !== [] ? $normalized : self::defaultImageModelPriority();
    }

    /**
     * @return list<string>
     */
    private function normalizeVideoModelPriorityList(mixed $list): array
    {
        if (! is_array($list) || $list === []) {
            return self::defaultVideoModelPriority();
        }

        $normalized = [];
        $videoOptions = array_keys(GoogleAiModelRegistry::videoSelectOptions());

        foreach ($list as $item) {
            $slug = is_string($item)
                ? trim($item)
                : trim((string) (is_array($item) ? ($item['slug'] ?? '') : ''));

            if ($slug === '') {
                continue;
            }

            $slug = GoogleAiModelRegistry::normalizeSlug($slug);
            if ($slug === '' || ! in_array($slug, $videoOptions, true)) {
                continue;
            }

            $normalized[] = $slug;
        }

        $normalized = array_values(array_unique(array_filter($normalized)));

        return $normalized !== [] ? $normalized : self::defaultVideoModelPriority();
    }

    public function getFeaturedSnippetPromptId(): ?int
    {
        $fromWorkflow = $this->getSettings()[self::KEY_FEATURED_SNIPPET_PROMPT_ID];
        if ($fromWorkflow !== null) {
            return $fromWorkflow;
        }

        return app(SeoPromptSettingsService::class)->getFeaturedSnippetPromptId();
    }

    public function getOutlineHeadingRegeneratorPromptId(): ?int
    {
        return $this->getSettings()[self::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID];
    }

    public function getProjectKeywordsPromptId(): ?int
    {
        return $this->getSettings()[self::KEY_PROJECT_KEYWORDS_PROMPT_ID];
    }

    public function getRenewFaqPromptId(): ?int
    {
        return $this->getSettings()[self::KEY_RENEW_FAQ_PROMPT_ID];
    }

    /**
     * Quy trình «Đăng bài viết» (tạo bài từ khóa, v.v.).
     */
    public function getPublishArticleTaskId(): ?int
    {
        return $this->getSettings()[self::KEY_PUBLISH_ARTICLE];
    }

    public function getRewriteArticleTaskId(): ?int
    {
        return $this->getSettings()[self::KEY_REWRITE_ARTICLE];
    }

    public function getPostReviewTaskId(): ?int
    {
        return $this->getSettings()[self::KEY_POST_REVIEW];
    }

    public function getCreateImageTaskId(): ?int
    {
        return $this->getSettings()[self::KEY_CREATE_IMAGE];
    }

    public function getLegacyCreateImagePromptId(): ?int
    {
        return $this->getSettings()[self::KEY_LEGACY_CREATE_IMAGE_PROMPT];
    }

    public function hasCreateImageConfiguration(): bool
    {
        // Editor «Tạo ảnh» dùng Typography / Infographic (không còn field «Ảnh bài viết» riêng).
        return $this->hasCreateTypographyImageConfiguration();
    }

    public function hasCreateTypographyImageConfiguration(): bool
    {
        $source = $this->getCreateTypographyImageSource();

        return $source === self::SOURCE_WORKFLOW
            ? $this->getCreateTypographyImageTaskId() !== null
            : $this->getCreateTypographyImagePromptId() !== null;
    }

    /**
     * @deprecated Dùng getCreateImageSource() + prompt/task theo source
     */
    public function getCreateImagePromptId(): ?int
    {
        return $this->getLegacyCreateImagePromptId();
    }

    public function getCreateImageSource(): string
    {
        return (string) $this->getSettings()[self::KEY_CREATE_IMAGE_SOURCE];
    }

    public function getCreateProductGalleryImagePromptId(): ?int
    {
        return $this->getSettings()[self::KEY_CREATE_PRODUCT_GALLERY_IMAGE];
    }

    public function getCreateProductGalleryImageTaskId(): ?int
    {
        return $this->getSettings()[self::KEY_CREATE_PRODUCT_GALLERY_TASK];
    }

    public function getCreateProductGallerySource(): string
    {
        return (string) $this->getSettings()[self::KEY_CREATE_PRODUCT_GALLERY_SOURCE];
    }

    public function getCreateTypographyImagePromptId(): ?int
    {
        return $this->getSettings()[self::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT];
    }

    public function getCreateTypographyImageTaskId(): ?int
    {
        return $this->getSettings()[self::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK];
    }

    public function getCreateTypographyImageSource(): string
    {
        return (string) $this->getSettings()[self::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE];
    }

    public function getCreateVideoPromptId(): ?int
    {
        return $this->getSettings()[self::KEY_CREATE_VIDEO];
    }

    public function getCreateVideoWorkflowTaskId(): ?int
    {
        return $this->getSettings()[self::KEY_CREATE_VIDEO_TASK];
    }

    public function getCreateVideoSource(): string
    {
        return (string) $this->getSettings()[self::KEY_CREATE_VIDEO_SOURCE];
    }

    public function getTranslateArticlePromptId(): ?int
    {
        return $this->getSettings()[self::KEY_TRANSLATE_ARTICLE_PROMPT_ID];
    }

    public function getArticleTitleSuggestionPromptId(): ?int
    {
        return $this->getSettings()[self::KEY_ARTICLE_TITLE_SUGGESTION_PROMPT_ID];
    }

    public function getArticleMetaDescriptionSuggestionPromptId(): ?int
    {
        return $this->getSettings()[self::KEY_ARTICLE_META_DESCRIPTION_SUGGESTION_PROMPT_ID];
    }

    /**
     * @deprecated Dùng getCreateVideoPromptId()
     */
    public function getCreateVideoTaskId(): ?int
    {
        return $this->getCreateVideoPromptId();
    }

    /**
     * @deprecated Alias của getPublishArticleTaskId()
     */
    public function getTaskId(): ?int
    {
        return $this->getPublishArticleTaskId();
    }

    /**
     * Partial merge: chỉ ghi key có trong $settings — không xóa Advanced khi lưu Editor Media và ngược lại.
     *
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        $existingData = WpOption::get(self::OPTION_KEY, []);
        $existingData = is_array($existingData) ? $existingData : [];
        $merged = $existingData;
        $patch = [];

        $hasPublish = array_key_exists(self::KEY_PUBLISH_ARTICLE, $settings)
            || array_key_exists(self::KEY_LEGACY_TASK_ID, $settings);
        if ($hasPublish) {
            $publish = $this->positiveIntOrNull(
                $settings[self::KEY_PUBLISH_ARTICLE] ?? $settings[self::KEY_LEGACY_TASK_ID] ?? null,
            );
            $patch[self::KEY_PUBLISH_ARTICLE] = $publish;
            $patch[self::KEY_LEGACY_TASK_ID] = $publish;
        }

        foreach ([
            self::KEY_REWRITE_ARTICLE,
            self::KEY_POST_REVIEW,
            self::KEY_CREATE_IMAGE,
            self::KEY_CREATE_PRODUCT_GALLERY_IMAGE,
            self::KEY_CREATE_PRODUCT_GALLERY_TASK,
            self::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT,
            self::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK,
            self::KEY_CREATE_VIDEO,
            self::KEY_CREATE_VIDEO_TASK,
            self::KEY_RENEW_FAQ_PROMPT_ID,
            self::KEY_PROJECT_KEYWORDS_PROMPT_ID,
            self::KEY_FEATURED_SNIPPET_PROMPT_ID,
            self::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID,
            self::KEY_TRANSLATE_ARTICLE_PROMPT_ID,
            self::KEY_ARTICLE_TITLE_SUGGESTION_PROMPT_ID,
            self::KEY_ARTICLE_META_DESCRIPTION_SUGGESTION_PROMPT_ID,
        ] as $intKey) {
            if (array_key_exists($intKey, $settings)) {
                $patch[$intKey] = $this->positiveIntOrNull($settings[$intKey] ?? null);
            }
        }

        if (array_key_exists(self::KEY_LEGACY_CREATE_IMAGE_PROMPT, $settings)
            || array_key_exists('create_image_prompt_id', $settings)
        ) {
            $patch[self::KEY_LEGACY_CREATE_IMAGE_PROMPT] = $this->positiveIntOrNull(
                $settings[self::KEY_LEGACY_CREATE_IMAGE_PROMPT]
                    ?? $settings['create_image_prompt_id']
                    ?? null,
            );
        }

        $createImageTask = array_key_exists(self::KEY_CREATE_IMAGE, $patch)
            ? $patch[self::KEY_CREATE_IMAGE]
            : $this->positiveIntOrNull($merged[self::KEY_CREATE_IMAGE] ?? null);
        $createImagePrompt = array_key_exists(self::KEY_LEGACY_CREATE_IMAGE_PROMPT, $patch)
            ? $patch[self::KEY_LEGACY_CREATE_IMAGE_PROMPT]
            : $this->positiveIntOrNull($merged[self::KEY_LEGACY_CREATE_IMAGE_PROMPT] ?? null);
        $galleryPrompt = array_key_exists(self::KEY_CREATE_PRODUCT_GALLERY_IMAGE, $patch)
            ? $patch[self::KEY_CREATE_PRODUCT_GALLERY_IMAGE]
            : $this->positiveIntOrNull($merged[self::KEY_CREATE_PRODUCT_GALLERY_IMAGE] ?? null);
        $galleryTask = array_key_exists(self::KEY_CREATE_PRODUCT_GALLERY_TASK, $patch)
            ? $patch[self::KEY_CREATE_PRODUCT_GALLERY_TASK]
            : $this->positiveIntOrNull($merged[self::KEY_CREATE_PRODUCT_GALLERY_TASK] ?? null);
        $typographyPrompt = array_key_exists(self::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT, $patch)
            ? $patch[self::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT]
            : $this->positiveIntOrNull($merged[self::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT] ?? null);
        $typographyTask = array_key_exists(self::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK, $patch)
            ? $patch[self::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK]
            : $this->positiveIntOrNull($merged[self::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK] ?? null);
        $videoPrompt = array_key_exists(self::KEY_CREATE_VIDEO, $patch)
            ? $patch[self::KEY_CREATE_VIDEO]
            : $this->positiveIntOrNull($merged[self::KEY_CREATE_VIDEO] ?? null);
        $videoTask = array_key_exists(self::KEY_CREATE_VIDEO_TASK, $patch)
            ? $patch[self::KEY_CREATE_VIDEO_TASK]
            : $this->positiveIntOrNull($merged[self::KEY_CREATE_VIDEO_TASK] ?? null);

        if (array_key_exists(self::KEY_CREATE_IMAGE_SOURCE, $settings)) {
            $patch[self::KEY_CREATE_IMAGE_SOURCE] = $this->normalizeSource(
                $settings[self::KEY_CREATE_IMAGE_SOURCE] ?? null,
                $createImageTask,
                $createImagePrompt,
            );
        }
        if (array_key_exists(self::KEY_CREATE_PRODUCT_GALLERY_SOURCE, $settings)) {
            $patch[self::KEY_CREATE_PRODUCT_GALLERY_SOURCE] = $this->normalizeSource(
                $settings[self::KEY_CREATE_PRODUCT_GALLERY_SOURCE] ?? null,
                $galleryTask,
                $galleryPrompt,
            );
        }
        if (array_key_exists(self::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE, $settings)) {
            $patch[self::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE] = $this->normalizeSource(
                $settings[self::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE] ?? null,
                $typographyTask,
                $typographyPrompt,
            );
        }
        if (array_key_exists(self::KEY_CREATE_VIDEO_SOURCE, $settings)) {
            $patch[self::KEY_CREATE_VIDEO_SOURCE] = $this->normalizeSource(
                $settings[self::KEY_CREATE_VIDEO_SOURCE] ?? null,
                $videoTask,
                $videoPrompt,
            );
        }

        if (array_key_exists(self::KEY_IMAGE_MODEL_PRIORITY, $settings)) {
            $patch[self::KEY_IMAGE_MODEL_PRIORITY] = $this->normalizeImageModelPriorityList(
                $settings[self::KEY_IMAGE_MODEL_PRIORITY] ?? null,
            );
        }
        if (array_key_exists(self::KEY_TYPOGRAPHY_MODEL_PRIORITY, $settings)) {
            $patch[self::KEY_TYPOGRAPHY_MODEL_PRIORITY] = $this->normalizeImageModelPriorityList(
                $settings[self::KEY_TYPOGRAPHY_MODEL_PRIORITY] ?? null,
            );
        }
        if (array_key_exists(self::KEY_VIDEO_MODEL_PRIORITY, $settings)) {
            $patch[self::KEY_VIDEO_MODEL_PRIORITY] = $this->normalizeVideoModelPriorityList(
                $settings[self::KEY_VIDEO_MODEL_PRIORITY] ?? null,
            );
        }
        if (array_key_exists(self::KEY_RENDERING_PREFERENCE, $settings)) {
            $patch[self::KEY_RENDERING_PREFERENCE] = RenderingPreference::fromMixed(
                $settings[self::KEY_RENDERING_PREFERENCE] ?? null,
            )->value;
        }
        if (array_key_exists(self::KEY_ADMIN_ENABLED_UNKNOWN_IMAGE_MODELS, $settings)) {
            $patch[self::KEY_ADMIN_ENABLED_UNKNOWN_IMAGE_MODELS] = $this->normalizeSlugList(
                $settings[self::KEY_ADMIN_ENABLED_UNKNOWN_IMAGE_MODELS] ?? null,
            );
        }
        if (array_key_exists(self::KEY_TYPOGRAPHY_VALIDATION_ENABLED, $settings)) {
            $patch[self::KEY_TYPOGRAPHY_VALIDATION_ENABLED] = (bool) ($settings[self::KEY_TYPOGRAPHY_VALIDATION_ENABLED] ?? true);
        }
        if (array_key_exists(self::KEY_TYPOGRAPHY_VALIDATION_LEVEL, $settings)) {
            $patch[self::KEY_TYPOGRAPHY_VALIDATION_LEVEL] = TypographyValidationLevel::fromMixed(
                $settings[self::KEY_TYPOGRAPHY_VALIDATION_LEVEL] ?? null,
            )->value;
        }
        if (array_key_exists(self::KEY_TYPOGRAPHY_MAX_CANDIDATES, $settings)) {
            $patch[self::KEY_TYPOGRAPHY_MAX_CANDIDATES] = $this->boundedIntOrNull(
                $settings[self::KEY_TYPOGRAPHY_MAX_CANDIDATES] ?? null,
                1,
                3,
            );
        }
        if (array_key_exists(self::KEY_TYPOGRAPHY_PASS_THRESHOLD, $settings)) {
            $patch[self::KEY_TYPOGRAPHY_PASS_THRESHOLD] = $this->boundedFloatOrNull(
                $settings[self::KEY_TYPOGRAPHY_PASS_THRESHOLD] ?? null,
                0.0,
                1.0,
            );
        }
        if (array_key_exists(self::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK, $settings)) {
            $patch[self::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK] = (bool) (
                $settings[self::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK] ?? false
            );
        }
        if (array_key_exists(self::KEY_TYPOGRAPHY_VALIDATION_MODEL, $settings)) {
            $patch[self::KEY_TYPOGRAPHY_VALIDATION_MODEL] = $this->normalizeOptionalModelSlug(
                $settings[self::KEY_TYPOGRAPHY_VALIDATION_MODEL] ?? null,
            );
        }

        // Migrate-on-save: lần đầu lưu Advanced mà chưa có typography priority → copy image priority.
        if (array_key_exists(self::KEY_IMAGE_MODEL_PRIORITY, $patch)
            && ! array_key_exists(self::KEY_TYPOGRAPHY_MODEL_PRIORITY, $merged)
            && ! array_key_exists(self::KEY_TYPOGRAPHY_MODEL_PRIORITY, $patch)
        ) {
            $patch[self::KEY_TYPOGRAPHY_MODEL_PRIORITY] = $patch[self::KEY_IMAGE_MODEL_PRIORITY];
        }

        WpOption::set(self::OPTION_KEY, array_merge($merged, $patch), 'no');
    }

    /**
     * @return array{
     *     publish_article_task_id: ?int,
     *     post_review_task_id: ?int,
     *     create_image_task_id: ?int,
     *     create_video_task_id: ?int,
     * }
     */
    private function emptySettings(): array
    {
        return [
            self::KEY_PUBLISH_ARTICLE => null,
            self::KEY_REWRITE_ARTICLE => null,
            self::KEY_POST_REVIEW => null,
            self::KEY_CREATE_IMAGE => null,
            self::KEY_LEGACY_CREATE_IMAGE_PROMPT => null,
            self::KEY_CREATE_PRODUCT_GALLERY_IMAGE => null,
            self::KEY_CREATE_PRODUCT_GALLERY_TASK => null,
            self::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT => null,
            self::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK => null,
            self::KEY_CREATE_VIDEO => null,
            self::KEY_CREATE_VIDEO_TASK => null,
            self::KEY_CREATE_IMAGE_SOURCE => self::SOURCE_WORKFLOW,
            self::KEY_CREATE_PRODUCT_GALLERY_SOURCE => self::SOURCE_PROMPT,
            self::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE => self::SOURCE_PROMPT,
            self::KEY_CREATE_VIDEO_SOURCE => self::SOURCE_PROMPT,
            self::KEY_RENEW_FAQ_PROMPT_ID => null,
            self::KEY_PROJECT_KEYWORDS_PROMPT_ID => null,
            self::KEY_FEATURED_SNIPPET_PROMPT_ID => null,
            self::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID => null,
            self::KEY_TRANSLATE_ARTICLE_PROMPT_ID => null,
            self::KEY_IMAGE_MODEL_PRIORITY => self::defaultImageModelPriority(),
            self::KEY_TYPOGRAPHY_MODEL_PRIORITY => self::defaultImageModelPriority(),
            self::KEY_VIDEO_MODEL_PRIORITY => self::defaultVideoModelPriority(),
            self::KEY_RENDERING_PREFERENCE => RenderingPreference::Balanced->value,
            self::KEY_ADMIN_ENABLED_UNKNOWN_IMAGE_MODELS => [],
            self::KEY_TYPOGRAPHY_VALIDATION_ENABLED => true,
            self::KEY_TYPOGRAPHY_VALIDATION_LEVEL => TypographyValidationLevel::Balanced->value,
            self::KEY_TYPOGRAPHY_MAX_CANDIDATES => null,
            self::KEY_TYPOGRAPHY_PASS_THRESHOLD => null,
            self::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK => false,
            self::KEY_TYPOGRAPHY_VALIDATION_MODEL => null,
        ];
    }

    private function resolveSource(mixed $stored, ?int $taskId, ?int $promptId): string
    {
        return $this->normalizeSource($stored, $taskId, $promptId);
    }

    private function normalizeSource(mixed $stored, ?int $taskId, ?int $promptId): string
    {
        $explicit = strtolower(trim((string) ($stored ?? '')));
        if ($explicit === self::SOURCE_PROMPT || $explicit === self::SOURCE_WORKFLOW) {
            return $explicit;
        }

        // Migrate-on-load: task ưu tiên → workflow; chỉ có prompt → prompt.
        if ($taskId !== null) {
            return self::SOURCE_WORKFLOW;
        }

        if ($promptId !== null) {
            return self::SOURCE_PROMPT;
        }

        return self::SOURCE_WORKFLOW;
    }

    /**
     * @return list<string>
     */
    private function normalizeSlugList(mixed $list): array
    {
        if (! is_array($list) || $list === []) {
            return [];
        }

        $normalized = [];
        foreach ($list as $item) {
            $slug = is_string($item)
                ? trim($item)
                : trim((string) (is_array($item) ? ($item['slug'] ?? '') : ''));
            if ($slug === '') {
                continue;
            }
            $normalized[] = GoogleAiModelRegistry::normalizeSlug($slug);
        }

        return array_values(array_unique(array_filter($normalized)));
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
