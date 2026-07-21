<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\ProductReview\ProductReviewAutomationSettingsResolver;
use App\Addons\SeoContentAi\Services\WordPress\ArticleWordPressBusinessSequence;
use App\Addons\SeoContentAi\Services\WordPress\ManualSyncContext;
use App\Addons\SeoContentAi\Support\SeoQueueContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Explicit manual WordPress sync — same business sequence as linear rule (no Automation gate).
 */
final class ManualWordPressSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $auditMeta
     */
    public function __construct(
        public readonly int $articleId,
        public readonly int $userId,
        public readonly string $source,
        public readonly string $requestId,
        public readonly string $correlationId,
        public readonly int $domainId,
        public readonly string $requestedAt,
        public readonly array $settings = [],
        public readonly array $auditMeta = [],
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function handle(
        ArticleWordPressBusinessSequence $sequence,
        BusinessHookEmitter $emitter,
        ArticleWpSyncQueueService $syncQueue,
        ProductReviewAutomationSettingsResolver $reviewSettingsResolver,
    ): void {
        $article = SeoArticle::query()->find($this->articleId);
        if (! $article instanceof SeoArticle) {
            Log::warning('manual_wordpress_sync.article_missing', [
                'article_id' => $this->articleId,
                'request_id' => $this->requestId,
            ]);

            return;
        }

        $manual = new ManualSyncContext(
            initiatedBy: $this->userId,
            source: $this->source,
            articleId: $this->articleId,
            domainId: $this->domainId > 0 ? $this->domainId : (int) ($article->site_id ?? 0),
            correlationId: $this->correlationId,
            requestId: $this->requestId,
            requestedAt: $this->requestedAt,
            manual: true,
        );
        $sideEffect = $manual->toSideEffectContext('manual_sync:'.$this->source);
        $mode = (string) ($this->settings['mode'] ?? 'sync');
        /** @var array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null $seoOverride */
        $seoOverride = is_array($this->settings['seo_override'] ?? null)
            ? $this->settings['seo_override']
            : null;
        $slug = (string) ($this->settings['slug'] ?? $article->slug ?? '');
        $syncProductReviews = ($this->settings['sync_product_reviews'] ?? true) !== false;
        $reviewSettings = $reviewSettingsResolver->resolve(
            is_array($this->settings['product_review'] ?? null) ? $this->settings['product_review'] : [],
        );

        $syncQueue->markProcessing($article);

        try {
            $result = SeoQueueContext::runWpSyncFromQueue(function () use (
                $sequence,
                $mode,
                $article,
                $sideEffect,
                $seoOverride,
                $slug,
                $syncProductReviews,
                $reviewSettings,
            ): array {
                return $sequence->run(
                    $article,
                    $sideEffect,
                    $mode,
                    $seoOverride,
                    $slug,
                    $syncProductReviews,
                    $reviewSettings,
                );
            });
        } catch (Throwable $e) {
            report($e);
            $syncQueue->markFailed($article, $e->getMessage(), emitFailedEvent: false);
            $emitter->wordpressSyncFailed($article, $e->getMessage(), $manual->toAuditMeta());
            throw $e;
        }

        $article = $article->fresh() ?? $article;
        if (! ($result['success'] ?? false)) {
            $message = (string) ($result['message'] ?? 'WordPress sync failed.');
            $syncQueue->markFailed($article, $message, emitFailedEvent: false);
            $emitter->wordpressSyncFailed(
                $article,
                $message,
                $manual->toAuditMeta(),
            );

            return;
        }

        $syncQueue->markCompleted($article, [
            'message' => (string) ($result['message'] ?? 'synced'),
            'wp_post_id' => (int) ($result['wp_post_id'] ?? $article->wp_post_id ?? 0) ?: null,
            'permalink' => (string) ($result['permalink'] ?? $article->permalink ?? '') ?: null,
            'origin' => 'manual',
        ], emitSyncedEvent: false);

        $emitter->wordpressSyncedOnce($article, $this->requestId, [
            'wp_post_id' => (int) ($result['wp_post_id'] ?? $article->wp_post_id ?? 0) ?: null,
            'message' => (string) ($result['message'] ?? 'synced'),
            'origin' => 'manual',
            'manual' => true,
            'source' => $this->source,
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
            'product_review_create' => $result['product_review_create'] ?? null,
            'product_review_sync' => $result['product_review_sync'] ?? null,
        ], $manual->toAuditMeta());

        Log::info('manual_wordpress_sync.completed', array_merge($manual->toAuditMeta(), [
            'mode' => $mode,
            'wp_post_id' => (int) ($article->wp_post_id ?? 0) ?: null,
        ]));
    }
}
