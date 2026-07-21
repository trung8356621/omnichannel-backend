<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;

final class ArticleEditorPersistService
{
    public function __construct(
        private readonly ArticleEditorHtmlSanitizeService $htmlSanitize,
        private readonly ArticleFaqBodySyncService $faqBodySync,
        private readonly ArticlePostImagesService $postImages,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly ArticleKeywordLinkReconcileService $keywordLinks,
        private readonly SeoArticleRevisionService $revisions,
    ) {}

    /**
     * @return array{success: bool, message: string, html?: string, faq_extracted?: bool, faq_count?: int}
     */
    public function persistLocal(
        SeoArticle $article,
        ArticleEditorSaveContext $context,
        string $html,
        bool $deferSeoAnalysis = true,
        ?array $seoAnalysis = null,
    ): array {
        $html = $this->persistLocalSilent($article, $context, $html);

        if (strlen(trim($html)) < 50 && $this->articleHadSubstantialContent($article)) {
            return [
                'success' => false,
                'message' => 'Editor trả về nội dung rỗng. Hãy thử lại hoặc dùng Lấy từ WordPress / Restore trước khi lưu.',
            ];
        }

        if ($deferSeoAnalysis) {
            // Full cutover: không auto SEO score. Emit content_updated → Automation rule.
            app(\App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter::class)
                ->articleContentUpdated($article->fresh() ?? $article);
        }

        $saveBody = 'Content is saved only in SEO system. Use "Sync" to push to WordPress.';

        return [
            'success' => true,
            'message' => $saveBody,
            'html' => $html,
        ];
    }

    public function persistLocalSilent(
        SeoArticle $article,
        ArticleEditorSaveContext $context,
        string $html,
    ): string {
        $html = $this->htmlSanitize->stripTransientEditorMarkup($html);
        $html = $this->guardArticleBodyBeforeSave($article, $html);

        $faqSync = $this->faqBodySync->extractFromBodyWhenMissing($article, $html);
        $html = $faqSync['body_html'];

        $slug = $context->normalizedSlug();
        $publishAt = $context->resolvePublishAtForSave();
        $postType = SeoProjectTask::normalizePostType($context->postType);

        $article->update([
            'title' => trim($context->title),
            'slug' => $slug !== '' ? $slug : null,
            'type' => $postType,
            'status' => $context->status,
            'published_at' => $publishAt,
            'body' => $html,
            'user_id' => auth()->id(),
        ]);

        $this->postImages->syncFromHtml($article, $html);
        $article->refresh();

        $this->syncFlags->markLocalEditPending($article);

        $this->revisions->captureAfterSave(
            $article->fresh(),
            trim($context->title),
            $html,
            [
                'seo_title' => trim($context->title),
                'meta_description' => trim($context->seoMetaDescription),
                'focus_keyword' => trim($context->focusKeyword),
                'seo_score' => $article->seo_score !== null ? (float) $article->seo_score : null,
                'slug' => $slug,
            ],
            auth()->id() !== null ? (int) auth()->id() : null,
        );

        $this->keywordLinks->reconcileForArticle($article->fresh(), $html);

        return $html;
    }

    private function guardArticleBodyBeforeSave(SeoArticle $article, string $html): string
    {
        $html = trim($html);
        if (strlen($html) >= 200) {
            return $html;
        }

        $existingBody = trim((string) ($article->body ?? ''));
        if (strlen($existingBody) >= 200) {
            return $existingBody;
        }

        $article->loadMissing('articleMetas');
        $wpCached = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_post_content')?->meta_value ?? ''));

        if (strlen($wpCached) >= 200) {
            return $wpCached;
        }

        return $html;
    }

    private function articleHadSubstantialContent(SeoArticle $article): bool
    {
        if (trim((string) ($article->body ?? '')) !== '') {
            return true;
        }

        $article->loadMissing('articleMetas');
        $cached = trim((string) ($article->articleMetas
            ->where('meta_key', 'wp_post_content')
            ->value('meta_value') ?? ''));

        if (strlen($cached) >= 200) {
            return true;
        }

        return $article->headings()->exists();
    }
}
