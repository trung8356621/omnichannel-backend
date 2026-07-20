<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\ManualWordPressContext;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\UnauthorizedWordPressSideEffectException;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Services\WordPressLocalMediaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Đồng bộ ảnh inline trong body + WebP backfill sau khi editor-sync nhanh đã chạy.
 */
final class SyncArticleBodyMediaToWordPressJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    /**
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     */
    public function __construct(
        public int $articleId,
        public ?array $seoOverride = null,
        public int $manualUserId = 0,
        public string $manualRequestId = '',
        public string $manualReason = 'body_media_followup',
        public string $manualCorrelationId = '',
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        WordPressArticleSyncService $syncService,
        WordPressLocalMediaSyncService $localMediaSync,
        ArticleWordPressSyncFlagService $syncFlags,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();

        $article = SeoArticle::query()->find($this->articleId);
        if (! $article instanceof SeoArticle) {
            return;
        }

        if ((int) ($article->site_id ?? 0) > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection((int) $article->site_id);
            $article = SeoArticle::query()->find($this->articleId);
        }

        if (! $article instanceof SeoArticle || (int) ($article->wp_post_id ?? 0) <= 0) {
            return;
        }

        if ($this->manualUserId <= 0 || $this->manualRequestId === '') {
            Log::channel('wordpress-side-effect')->error('wordpress.side_effect.blocked', [
                'operation' => 'article.body_media_sync',
                'article_id' => $this->articleId,
                'queue_job_class' => self::class,
                'message' => 'Missing manual context on body media job.',
            ]);

            return;
        }

        $sideEffect = new ManualWordPressContext(
            userId: $this->manualUserId,
            requestId: $this->manualRequestId,
            articleId: (int) $article->id,
            siteId: (int) ($article->site_id ?? 0),
            reason: $this->manualReason !== '' ? $this->manualReason : 'body_media_followup',
            correlationId: $this->manualCorrelationId !== '' ? $this->manualCorrelationId : $this->manualRequestId,
        );

        $syncFlags->markBodyMediaSyncPending($article);

        try {
            $context = $syncService->resolveEditorSyncContext($article);
            if (! ($context['success'] ?? false)) {
                Log::warning('SyncArticleBodyMediaToWordPressJob: missing WP context', [
                    'article_id' => $this->articleId,
                ]);

                return;
            }

            $prepared = $syncService->prepareEditorSyncPayload($article, $this->seoOverride, [
                'defer_inline_media_sync' => false,
                'defer_finalize_media' => false,
            ]);

            if ($localMediaSync->htmlContainsLocalSeoMedia((string) ($prepared['post_content'] ?? ''))) {
                $prepared['skip_editor_sync'] = false;
                $prepared['skip_editor_sync_reason'] = 'pending_local_media';
            }

            $httpResult = $syncService->executeEditorSyncRequest($article, $sideEffect, $context, $prepared);
            if (! ($httpResult['success'] ?? false)) {
                Log::warning('SyncArticleBodyMediaToWordPressJob: editor-sync patch failed', [
                    'article_id' => $this->articleId,
                    'message' => $httpResult['message'] ?? '',
                ]);

                return;
            }

            $decoded = is_array($httpResult['decoded'] ?? null) ? $httpResult['decoded'] : [];
            $syncService->completeEditorSyncResponse($article->fresh(), $prepared, $decoded, [
                'defer_inline_media_sync' => false,
                'defer_finalize_media' => false,
                'skip_featured_media_push' => true,
            ]);
        } catch (UnauthorizedWordPressSideEffectException $exception) {
            Log::channel('wordpress-side-effect')->error('wordpress.side_effect.blocked', $exception->traceContext);
        } catch (Throwable $exception) {
            Log::warning('SyncArticleBodyMediaToWordPressJob exception', [
                'article_id' => $this->articleId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $fresh = SeoArticle::query()->find($this->articleId);
            if ($fresh instanceof SeoArticle) {
                $syncFlags->clearBodyMediaSyncPending($fresh);
            }
        }
    }
}
