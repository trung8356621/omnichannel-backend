<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\WordPress;

use App\Addons\SeoContentAi\Automation\Contracts\BusinessActionDispatcher;
use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Jobs\ManualWordPressSyncJob;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorBundleApplyService;
use App\Addons\SeoContentAi\Services\ArticleWpSyncLeaseService;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Manual WordPress UI entry → domain sync (no Automation Rule / AvailabilityGate).
 * Local persist trước sync đi qua BusinessActionDispatcher (article.content.update).
 */
final class WordPressManualSyncService
{
    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleWpSyncQueueService $syncQueue,
        private readonly ArticleWpSyncLeaseService $lease,
        private readonly BusinessActionDispatcher $actions,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function enqueueFromEditorBundle(SeoArticle $article, array $bundle, User $actor, string $initiatedFrom): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        abort_unless($actor->getKey() > 0, 403);

        $bundle = $this->syncQueue->applyPublishImmediatelyToBundle($bundle);
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
                'origin' => 'manual_wordpress_sync',
                'correlation_id' => Str::uuid()->toString(),
                'actor_id' => (int) $actor->id,
                'site_id' => (int) ($fresh->site_id ?? 0) ?: null,
            ]),
        );

        if (! $persist->success) {
            return [
                'success' => false,
                'status' => 'blocked',
                'message' => (string) ($persist->error['message'] ?? 'Không lưu được bài trước khi sync WordPress.'),
            ];
        }

        $article = $fresh->fresh() ?? $fresh;
        $publishImmediately = (bool) filter_var(
            data_get($bundle, 'publish_box.publish_immediately', false),
            FILTER_VALIDATE_BOOL,
        );

        return $this->enqueueManual(
            $article,
            $actor,
            $initiatedFrom,
            [
                'mode' => $publishImmediately ? 'publish' : 'sync',
                'seo_override' => $context->seoPayloadForWordPress(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function resyncQueued(SeoArticle $article, User $actor, string $initiatedFrom): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return $this->enqueueManual($article, $actor, $initiatedFrom, ['mode' => 'sync']);
    }

    /**
     * @param  array<string, mixed>|null  $seoOverride
     * @return array<string, mixed>
     */
    public function publishNow(SeoArticle $article, User $actor, string $initiatedFrom, ?array $seoOverride = null): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return $this->enqueueManual(
            $article,
            $actor,
            $initiatedFrom,
            [
                'mode' => 'publish',
                'seo_override' => $seoOverride ?? [],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $seoOverride
     * @return array<string, mixed>
     */
    public function syncSeoMeta(SeoArticle $article, User $actor, string $initiatedFrom, array $seoOverride): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return $this->enqueueManual(
            $article,
            $actor,
            $initiatedFrom,
            [
                'mode' => 'seo_meta',
                'seo_override' => $seoOverride,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function syncSlug(SeoArticle $article, User $actor, string $initiatedFrom, string $slug): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return $this->enqueueManual(
            $article,
            $actor,
            $initiatedFrom,
            [
                'mode' => 'slug',
                'slug' => $slug,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function enqueueManual(
        SeoArticle $article,
        User $actor,
        string $initiatedFrom,
        array $settings,
    ): array {
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return $this->blocked(
                'CONNECTION_MISSING',
                __('seo-content-ai::filament.automation.manual_sync_no_site'),
            );
        }

        $lockKey = 'manual-wp-sync:'.(int) $article->id;
        $lock = Cache::lock($lockKey, 120);
        if (! $lock->get()) {
            // isActive() tự clear meta mồ côi (pending không có job).
            if ($this->syncQueue->isActive($article)) {
                return $this->deduplicated($this->syncQueue->activeOperation($article) ?? [], $article);
            }

            return $this->blocked(
                'SYNC_IN_PROGRESS',
                __('seo-content-ai::filament.automation.manual_sync_in_progress'),
            );
        }

        try {
            if ($this->syncQueue->isActive($article)) {
                $active = $this->syncQueue->activeOperation($article);

                return $this->deduplicated($active ?? [], $article);
            }

            $manual = ManualSyncContext::make(
                initiatedBy: (int) $actor->getKey(),
                source: $initiatedFrom !== '' ? $initiatedFrom : 'editor',
                articleId: (int) $article->id,
                domainId: $siteId,
            );

            $syncJob = $this->lease->enqueue(
                article: $article,
                source: $manual->source,
                initiatedBy: $manual->initiatedBy,
                requestId: $manual->requestId,
                correlationId: $manual->correlationId,
                settings: $settings,
                auditMeta: $manual->toAuditMeta(),
            );

            if ((string) $syncJob->request_id !== $manual->requestId) {
                return $this->deduplicated($this->lease->toOperationPayload($syncJob), $article);
            }

            ManualWordPressSyncJob::dispatch(
                articleId: (int) $article->id,
                userId: $manual->initiatedBy,
                source: $manual->source,
                requestId: $manual->requestId,
                correlationId: $manual->correlationId,
                domainId: $manual->domainId,
                requestedAt: $manual->requestedAt,
                syncJobId: (int) $syncJob->id,
                settings: $settings,
                auditMeta: $manual->toAuditMeta(),
            )->afterCommit();

            Log::info('manual_wordpress_sync.queued', array_merge($manual->toAuditMeta(), [
                'article_id' => (int) $article->id,
                'sync_id' => (int) $syncJob->id,
                'sync_job_id' => (int) $syncJob->id,
                'site_id' => $siteId,
                'queue_name' => ArticleWpSyncQueueService::QUEUE_NAME,
                'status' => $syncJob->status?->value ?? 'pending',
                'endpoint' => 'manual_wordpress_sync.enqueue',
            ]));

            return [
                'success' => true,
                'status' => 'dispatched',
                'queued' => true,
                'already_queued' => false,
                'message' => __('seo-content-ai::filament.automation.manual_sync_queued'),
                'manual' => true,
                'request_id' => $manual->requestId,
                'correlation_id' => $manual->correlationId,
                'source' => $manual->source,
                'initiated_by' => $manual->initiatedBy,
                'execution_id' => null,
                'rule_code' => null,
                'sync_id' => (int) $syncJob->id,
                'sync_job_id' => (int) $syncJob->id,
                'data' => [
                    'sync_id' => (int) $syncJob->id,
                    'status' => 'queued',
                    'already_queued' => false,
                ],
                'operation' => $this->lease->toOperationPayload($syncJob),
                'notification' => [
                    'title' => __('seo-content-ai::filament.automation.manual_sync_queued_title'),
                    'body' => __('seo-content-ai::filament.automation.manual_sync_queued'),
                    'status' => 'success',
                ],
            ];
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // lock may already be released
            }
        }
    }

    /**
     * @param  array<string, mixed>  $active
     * @return array<string, mixed>
     */
    private function deduplicated(array $active, SeoArticle $article): array
    {
        $message = __('seo-content-ai::filament.automation.manual_sync_already_queued');
        $operation = $active !== [] ? $active : ($this->syncQueue->activeOperation($article) ?? []);
        $syncId = (int) ($operation['sync_job_id'] ?? $operation['id'] ?? 0);

        return [
            'success' => true,
            'status' => 'deduplicated',
            'queued' => true,
            'already_queued' => true,
            'message' => $message,
            'manual' => true,
            'sync_id' => $syncId > 0 ? $syncId : null,
            'sync_job_id' => $syncId > 0 ? $syncId : null,
            'data' => [
                'sync_id' => $syncId > 0 ? $syncId : null,
                'status' => (string) ($operation['status'] ?? 'queued'),
                'already_queued' => true,
            ],
            'operation' => $operation !== [] ? $operation : null,
            'request_id' => $active['request_id'] ?? $operation['request_id'] ?? null,
            'correlation_id' => $active['correlation_id'] ?? $operation['correlation_id'] ?? null,
            'notification' => [
                'title' => __('seo-content-ai::filament.automation.manual_sync_queued_title'),
                'body' => $message,
                'status' => 'info',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blocked(string $code, string $message): array
    {
        return [
            'success' => false,
            'status' => 'blocked',
            'queued' => false,
            'message' => $message,
            'data' => null,
            'error_code' => $code,
            'manual' => true,
            'notification' => [
                'title' => __('seo-content-ai::filament.automation.manual_sync_blocked_title'),
                'body' => $message,
                'status' => 'warning',
            ],
        ];
    }
}
