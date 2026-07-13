<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorSyncOrchestrator;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Support\SeoQueueContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
    ) {}

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

        $bundle = $queueService->readQueueBundle($article);
        if ($bundle === []) {
            $queueService->markFailed($article, 'Thiếu dữ liệu đồng bộ trong article_meta.');

            return;
        }

        SeoQueueContext::runWpSyncFromQueue(function () use ($queueService, $syncOrchestrator, $article, $bundle): void {
            $queueService->markProcessing($article);

            try {
                $result = $syncOrchestrator->syncFromEditorBundle($article->fresh() ?? $article, $bundle, fromQueue: true);

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
            } catch (Throwable $exception) {
                Log::warning('SyncArticleToWordPressFromQueueJob failed', [
                    'article_id' => $this->articleId,
                    'error' => $exception->getMessage(),
                ]);

                $fresh = SeoArticle::query()->find($this->articleId);
                if ($fresh instanceof SeoArticle) {
                    $queueService->markFailed($fresh, $exception->getMessage());
                }
            }
        });
    }
}
