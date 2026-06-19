<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Support\GoogleAiModelRegistry;
use App\Models\WpOption;

final class SeoCreateArticleSettingsService
{
    public const OPTION_KEY = 'seo_create_article_task';

    public const KEY_PUBLISH_ARTICLE = 'publish_article_task_id';

    public const KEY_REWRITE_ARTICLE = 'rewrite_article_task_id';

    public const KEY_POST_REVIEW = 'post_review_task_id';

    public const KEY_CREATE_IMAGE = 'create_image_prompt_id';

    public const KEY_CREATE_PRODUCT_GALLERY_IMAGE = 'create_product_gallery_image_prompt_id';

    public const KEY_CREATE_VIDEO = 'create_video_prompt_id';

    public const KEY_RENEW_FAQ_PROMPT_ID = 'renew_faq_prompt_id';

    public const KEY_PROJECT_KEYWORDS_PROMPT_ID = 'project_keywords_prompt_id';

    /** Prompt sinh Featured Snippet trên editor bài viết (biến {{input}} = từ khóa chính). */
    public const KEY_FEATURED_SNIPPET_PROMPT_ID = 'featured_snippet_prompt_id';

    /** Prompt tái sinh heading từ tab Outline (nút AI gen). */
    public const KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID = 'outline_heading_regenerator_prompt_id';

    /** Prompt dịch bài viết (nút Dịch nhanh trên bản dịch liên kết). */
    public const KEY_TRANSLATE_ARTICLE_PROMPT_ID = 'translate_article_prompt_id';

    /** Thứ tự ưu tiên model sinh ảnh (slug API, vd. gemini-2.5-flash-image). */
    public const KEY_IMAGE_MODEL_PRIORITY = 'image_model_priority';

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

        return [
            self::KEY_PUBLISH_ARTICLE => $publish,
            self::KEY_REWRITE_ARTICLE => $this->positiveIntOrNull($data[self::KEY_REWRITE_ARTICLE] ?? null),
            self::KEY_POST_REVIEW => $this->positiveIntOrNull($data[self::KEY_POST_REVIEW] ?? null),
            self::KEY_CREATE_IMAGE => $this->positiveIntOrNull($data[self::KEY_CREATE_IMAGE] ?? null),
            self::KEY_CREATE_PRODUCT_GALLERY_IMAGE => $this->positiveIntOrNull($data[self::KEY_CREATE_PRODUCT_GALLERY_IMAGE] ?? null),
            self::KEY_CREATE_VIDEO => $this->positiveIntOrNull($data[self::KEY_CREATE_VIDEO] ?? null),
            self::KEY_RENEW_FAQ_PROMPT_ID => $this->positiveIntOrNull($data[self::KEY_RENEW_FAQ_PROMPT_ID] ?? null),
            self::KEY_PROJECT_KEYWORDS_PROMPT_ID => $this->positiveIntOrNull($data[self::KEY_PROJECT_KEYWORDS_PROMPT_ID] ?? null),
            self::KEY_FEATURED_SNIPPET_PROMPT_ID => $this->positiveIntOrNull($data[self::KEY_FEATURED_SNIPPET_PROMPT_ID] ?? null),
            self::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID => $this->positiveIntOrNull(
                $data[self::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID] ?? null,
            ),
            self::KEY_TRANSLATE_ARTICLE_PROMPT_ID => $this->positiveIntOrNull(
                $data[self::KEY_TRANSLATE_ARTICLE_PROMPT_ID] ?? null,
            ),
            self::KEY_IMAGE_MODEL_PRIORITY => $this->normalizeImageModelPriorityForForm(
                $data[self::KEY_IMAGE_MODEL_PRIORITY] ?? null,
            ),
        ];
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
        return [
            'gemini-2.5-flash-image',
            'gemini-2.5-pro-image',
            'imagen-4.0-generate-001',
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

            $normalized[] = GoogleAiModelRegistry::normalizeSlug($slug);
        }

        $normalized = array_values(array_unique(array_filter($normalized)));

        return $normalized !== [] ? $normalized : self::defaultImageModelPriority();
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

    public function getCreateImagePromptId(): ?int
    {
        return $this->getSettings()[self::KEY_CREATE_IMAGE];
    }

    public function getCreateProductGalleryImagePromptId(): ?int
    {
        $settings = $this->getSettings();

        return $settings[self::KEY_CREATE_PRODUCT_GALLERY_IMAGE]
            ?? $settings[self::KEY_CREATE_IMAGE];
    }

    public function getCreateVideoPromptId(): ?int
    {
        return $this->getSettings()[self::KEY_CREATE_VIDEO];
    }

    public function getTranslateArticlePromptId(): ?int
    {
        return $this->getSettings()[self::KEY_TRANSLATE_ARTICLE_PROMPT_ID];
    }

    /**
     * @deprecated Dùng getCreateImagePromptId()
     */
    public function getCreateImageTaskId(): ?int
    {
        return $this->getCreateImagePromptId();
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
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        $publish = $this->positiveIntOrNull($settings[self::KEY_PUBLISH_ARTICLE] ?? $settings[self::KEY_LEGACY_TASK_ID] ?? null);

        WpOption::set(self::OPTION_KEY, [
            self::KEY_PUBLISH_ARTICLE => $publish,
            self::KEY_REWRITE_ARTICLE => $this->positiveIntOrNull($settings[self::KEY_REWRITE_ARTICLE] ?? null),
            self::KEY_POST_REVIEW => $this->positiveIntOrNull($settings[self::KEY_POST_REVIEW] ?? null),
            self::KEY_CREATE_IMAGE => $this->positiveIntOrNull($settings[self::KEY_CREATE_IMAGE] ?? null),
            self::KEY_CREATE_PRODUCT_GALLERY_IMAGE => $this->positiveIntOrNull($settings[self::KEY_CREATE_PRODUCT_GALLERY_IMAGE] ?? null),
            self::KEY_CREATE_VIDEO => $this->positiveIntOrNull($settings[self::KEY_CREATE_VIDEO] ?? null),
            self::KEY_RENEW_FAQ_PROMPT_ID => $this->positiveIntOrNull($settings[self::KEY_RENEW_FAQ_PROMPT_ID] ?? null),
            self::KEY_PROJECT_KEYWORDS_PROMPT_ID => $this->positiveIntOrNull($settings[self::KEY_PROJECT_KEYWORDS_PROMPT_ID] ?? null),
            self::KEY_FEATURED_SNIPPET_PROMPT_ID => $this->positiveIntOrNull($settings[self::KEY_FEATURED_SNIPPET_PROMPT_ID] ?? null),
            self::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID => $this->positiveIntOrNull(
                $settings[self::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID] ?? null,
            ),
            self::KEY_TRANSLATE_ARTICLE_PROMPT_ID => $this->positiveIntOrNull(
                $settings[self::KEY_TRANSLATE_ARTICLE_PROMPT_ID] ?? null,
            ),
            self::KEY_IMAGE_MODEL_PRIORITY => $this->normalizeImageModelPriorityList(
                $settings[self::KEY_IMAGE_MODEL_PRIORITY] ?? null,
            ),
            self::KEY_LEGACY_TASK_ID => $publish,
        ], 'no');
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
            self::KEY_CREATE_PRODUCT_GALLERY_IMAGE => null,
            self::KEY_CREATE_VIDEO => null,
            self::KEY_RENEW_FAQ_PROMPT_ID => null,
            self::KEY_PROJECT_KEYWORDS_PROMPT_ID => null,
            self::KEY_FEATURED_SNIPPET_PROMPT_ID => null,
            self::KEY_OUTLINE_HEADING_REGENERATOR_PROMPT_ID => null,
            self::KEY_TRANSLATE_ARTICLE_PROMPT_ID => null,
            self::KEY_IMAGE_MODEL_PRIORITY => self::defaultImageModelPriority(),
        ];
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
