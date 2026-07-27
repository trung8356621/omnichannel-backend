<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorBundleApplyService;
use App\Addons\SeoContentAi\Services\ArticlePostImagesService;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Automation\Contracts\BusinessActionDispatcher;
use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Models\User;
use App\Support\RuntimeLogger;
use Illuminate\Support\Str;

/**
 * Sync WP khi bài thuộc Content Project đang hoạt động:
 * chỉ Save Workspace (Laravel) — không gọi WordPress API / không publish.
 */
final class ContentProjectWorkspaceSaveService
{
    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly BusinessActionDispatcher $actions,
        private readonly ArticlePostImagesService $postImages,
        private readonly ContentProjectArticleMembership $membership,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function saveFromEditorBundle(SeoArticle $article, array $bundle, User $actor, string $initiatedFrom): array
    {
        if (! $this->membership->belongsToActiveContentProject($article)) {
            return [
                'success' => false,
                'status' => 'blocked',
                'message' => 'Article không thuộc Content Project đang hoạt động.',
            ];
        }

        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $this->bundleApply->apply($article, $bundle, $context);

        $html = (string) ($bundle['html'] ?? '');
        $fresh = $article->fresh() ?? $article;
        $persist = $this->actions->dispatch(
            'article.content.update',
            [
                'article_id' => (int) $fresh->id,
                'content' => $html,
                'title' => $context->title,
                'slug' => $context->slug,
                'status' => $context->status,
                'post_type' => $context->postType,
                'visibility' => $context->visibility,
                'publish_day' => $context->publishDay,
                'publish_month' => $context->publishMonth,
                'publish_year' => $context->publishYear,
                'publish_hour' => $context->publishHour,
                'publish_minute' => $context->publishMinute,
                'seo_meta_description' => $context->seoMetaDescription,
                'focus_keyword' => $context->focusKeyword,
            ],
            ActionContext::fromArray([
                'origin' => 'content_project_workspace_save',
                'correlation_id' => Str::uuid()->toString(),
                'actor_id' => (int) $actor->id,
                'site_id' => (int) ($fresh->site_id ?? 0) ?: null,
            ]),
        );

        if (! $persist->success) {
            return [
                'success' => false,
                'status' => 'blocked',
                'message' => (string) ($persist->error['message'] ?? 'Không lưu được workspace.'),
            ];
        }

        $article = $fresh->fresh() ?? $fresh;

        // Đồng bộ metadata / album nội bộ (không gọi WP).
        try {
            $this->postImages->syncFromHtml($article, (string) ($article->body ?? $html));
        } catch (\Throwable $e) {
            RuntimeLogger::warning('content_project_workspace_media_meta_sync_failed', [
                'article_id' => (int) $article->id,
                'message' => $e->getMessage(),
            ]);
        }

        $article->forceFill([
            'last_synced_at' => now(),
        ])->saveQuietly();

        $task = $this->membership->activeTaskForArticle($article);

        // Sync WP trong active Content Project: chỉ workspace — không đụng publishing schedule/status.
        RuntimeLogger::info('content_project_workspace_saved', [
            'article_id' => (int) $article->id,
            'project_id' => $task?->project_id,
            'task_id' => $task?->id,
            'actor_id' => (int) $actor->id,
            'initiated_from' => $initiatedFrom,
            'wp_api_called' => false,
            'schedule_touched' => false,
        ]);

        return [
            'success' => true,
            'status' => 'workspace_saved',
            'queued' => false,
            'already_queued' => false,
            'workspace_only' => true,
            'message' => __('seo-content-ai::filament.automation.content_project_workspace_saved'),
            'manual' => true,
            'data' => [
                'status' => 'workspace_saved',
                'last_synced_at' => $article->last_synced_at?->toIso8601String(),
            ],
            'notification' => [
                'title' => __('seo-content-ai::filament.automation.content_project_workspace_saved_title'),
                'body' => __('seo-content-ai::filament.automation.content_project_workspace_saved'),
                'status' => 'success',
            ],
        ];
    }
}
