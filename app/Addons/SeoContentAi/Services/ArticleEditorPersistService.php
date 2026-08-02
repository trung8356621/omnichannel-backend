<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectArticleMembership;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueService;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use Carbon\Carbon;

final class ArticleEditorPersistService
{
    public function __construct(
        private readonly ArticleEditorHtmlSanitizeService $htmlSanitize,
        private readonly ArticleFaqBodySyncService $faqBodySync,
        private readonly ArticlePostImagesService $postImages,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly ArticleKeywordLinkReconcileService $keywordLinks,
        private readonly SeoArticleRevisionService $revisions,
        private readonly ContentProjectArticleMembership $contentProjectMembership,
        private readonly ContentProjectPublishingQueueService $publishingQueue,
    ) {}

    /**
     * Persist only. Event emission (article.content_updated) là trách nhiệm của caller/Action
     * (UpdateArticleContentAction) — tránh emit trùng lặp khi service này được gọi qua Action.
     *
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

        return $this->buildPersistResult($article, $html);
    }

    /**
     * @return array{success: bool, message: string, html?: string}
     */
    public function buildPersistResult(SeoArticle $article, string $html): array
    {
        if (strlen(trim($html)) < 50 && $this->articleHadSubstantialContent($article)) {
            return [
                'success' => false,
                'message' => 'Editor trả về nội dung rỗng. Hãy thử lại hoặc dùng Lấy từ WordPress / Restore trước khi lưu.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Content is saved only in SEO system. Use "Sync" to push to WordPress.',
            'html' => $html,
        ];
    }

    public function persistLocalSilent(
        SeoArticle $article,
        ArticleEditorSaveContext $context,
        string $html,
    ): string {
        $html = $this->writeArticleRow($article, $context, $html);
        $this->runAfterPersistSideEffects($article, $context, $html);

        return $html;
    }

    /**
     * Critical section only: sanitize + UPDATE `articles` row.
     * Keep this free of heavy meta/revision/link work so callers can hold a short DB TX.
     */
    public function writeArticleRow(
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

        return $html;
    }

    /**
     * Post-row side effects — must run outside the short article-row transaction.
     */
    public function runAfterPersistSideEffects(
        SeoArticle $article,
        ArticleEditorSaveContext $context,
        string $html,
    ): void {
        $slug = $context->normalizedSlug();
        $publishAt = $context->resolvePublishAtForSave();

        $this->syncContentProjectScheduledPublish($article->fresh() ?? $article, $context->status, $publishAt);

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

    private function syncContentProjectScheduledPublish(
        SeoArticle $article,
        string $status,
        mixed $publishAt,
    ): void {
        $task = $this->contentProjectMembership->activeTaskForArticle($article);
        if (! $task instanceof SeoProjectTask) {
            return;
        }

        // Workflow AI persist runs while task is writing — never mirror schedule/unschedule
        // through ContentProjectItemActionGuard (Schedule blocked: «Generation is running»).
        $taskStatus = strtolower(trim((string) ($task->status ?? '')));
        if (in_array($taskStatus, [
            SeoProjectTask::STATUS_WRITING,
            SeoProjectTask::STATUS_PENDING,
            SeoProjectTask::STATUS_PROCESSING,
        ], true)) {
            return;
        }

        $project = SeoProject::query()->find((int) $task->project_id);
        if (! $project instanceof SeoProject) {
            return;
        }

        $taskId = (int) $task->id;

        // Schedule mirror qua Publishing Queue service (không stamp model ad-hoc).
        try {
            if ($status === 'scheduled' && $publishAt !== null) {
                $at = $publishAt instanceof Carbon ? $publishAt : Carbon::parse((string) $publishAt);
                $this->publishingQueue->schedule($project, [$taskId], $at);

                return;
            }

            if ($task->scheduled_publish_at !== null && $status !== 'scheduled') {
                $this->publishingQueue->unschedule($project, [$taskId]);
            }
        } catch (\RuntimeException) {
            // Fail-soft: content persist must not fail because schedule eligibility rejects.
        }
    }
}
