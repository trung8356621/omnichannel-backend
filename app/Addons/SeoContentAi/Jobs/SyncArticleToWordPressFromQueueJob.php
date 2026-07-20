<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorSyncOrchestrator;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\ManualWordPressContext;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\UnauthorizedWordPressSideEffectException;
use App\Addons\SeoContentAi\Support\SeoQueueContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class SyncArticleToWordPressFromQueueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $articleId,
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ArticleWpSyncQueueService $queueService,
        ArticleEditorSyncOrchestrator $syncOrchestrator,
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

        if (! $article instanceof SeoArticle) {
            return;
        }

        $queueMeta = $queueService->readQueueMeta($article);
        if ((string) ($queueMeta['status'] ?? '') !== ArticleWpSyncQueueService::STATUS_PENDING) {
            return;
        }

        // Firewall: queue job without explicit manual audit meta is blocked.
        if (($queueMeta['manual'] ?? false) !== true
            || (int) ($queueMeta['user_id'] ?? $queueMeta['initiated_by'] ?? 0) <= 0
            || trim((string) ($queueMeta['request_id'] ?? '')) === ''
        ) {
            $message = '['.UnauthorizedWordPressSideEffectException::ORIGIN_MISSING
                .'] SyncArticleToWordPressFromQueueJob missing ManualWordPressContext audit meta.';
            Log::channel('wordpress-side-effect')->error('wordpress.side_effect.blocked', [
                'operation' => 'queue.sync_article',
                'article_id' => $this->articleId,
                'queue_job_class' => self::class,
                'queue_name' => ArticleWpSyncQueueService::QUEUE_NAME,
                'queue_meta_keys' => array_keys($queueMeta),
                'message' => $message,
            ]);
            $queueService->markFailed($article, $message);

            return;
        }

        $sideEffect = new ManualWordPressContext(
            userId: (int) ($queueMeta['user_id'] ?? $queueMeta['initiated_by']),
            requestId: (string) $queueMeta['request_id'],
            articleId: (int) $article->id,
            siteId: (int) ($article->site_id ?? 0),
            reason: (string) ($queueMeta['reason'] ?? 'queued_manual_sync'),
            correlationId: (string) ($queueMeta['correlation_id'] ?? Str::uuid()),
        );

        $bundle = $queueService->readQueueBundle($article);
        if ($bundle === []) {
            $queueService->markFailed($article, 'Thiếu dữ liệu đồng bộ trong article_meta.');

            return;
        }

        SeoQueueContext::runWpSyncFromQueue(function () use ($queueService, $syncOrchestrator, $article, $bundle, $sideEffect): void {
            $queueService->markProcessing($article);

            try {
                $queueMeta = $queueService->readQueueMeta($article);
                if (filter_var($queueMeta['publish_immediately'] ?? false, FILTER_VALIDATE_BOOL)) {
                    $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];
                    $publishBox['publish_immediately'] = true;
                    $bundle['publish_box'] = $publishBox;
                }

                $result = $syncOrchestrator->syncFromEditorBundle(
                    $article->fresh() ?? $article,
                    $bundle,
                    $sideEffect,
                    fromQueue: true,
                );

                if (! ($result['success'] ?? false)) {
                    $message = (string) ($result['message'] ?? 'Đồng bộ WordPress thất bại.');

                    Log::warning('SyncArticleToWordPressFromQueueJob sync failed', [
                        'article_id' => $this->articleId,
                        'error' => $message,
                    ]);

                    $queueService->markFailed(
                        $article->fresh() ?? $article,
                        $message,
                    );

                    return;
                }

                $queueService->markCompleted($article->fresh() ?? $article, $result);
            } catch (UnauthorizedWordPressSideEffectException $exception) {
                Log::channel('wordpress-side-effect')->error('wordpress.side_effect.blocked', $exception->traceContext);
                $queueService->markFailed($article->fresh() ?? $article, $exception->getMessage());
            } catch (Throwable $exception) {
                Log::warning('SyncArticleToWordPressFromQueueJob failed', [
                    'article_id' => $this->articleId,
                    'error' => $exception->getMessage(),
                ]);
                $queueService->markFailed($article->fresh() ?? $article, $exception->getMessage());
            }
        });
    }
}
