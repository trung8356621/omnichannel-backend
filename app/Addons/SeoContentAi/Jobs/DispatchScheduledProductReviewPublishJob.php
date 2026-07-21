<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Enums\ArticleProductReviewStatus;
use App\Addons\SeoContentAi\Models\ArticleProductReview;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ProductReview\ProductReviewPublishDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * After schedule delay — emit publish_requested and run publish execution in-process.
 */
final class DispatchScheduledProductReviewPublishJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param  array<string, mixed>  $settingsSnapshot
     */
    public function __construct(
        public readonly int $reviewId,
        public readonly int $articleId,
        public readonly int $siteId,
        public readonly int $connectionId,
        public readonly string $publishIntent,
        public readonly array $settingsSnapshot = [],
        public readonly ?int $actorId = null,
    ) {
        $this->onQueue('automation-external');
    }

    public function handle(ProductReviewPublishDispatchService $dispatchService): void
    {
        $review = ArticleProductReview::query()->find($this->reviewId);
        if (! $review instanceof ArticleProductReview) {
            return;
        }

        if ($review->status === ArticleProductReviewStatus::Cancelled
            || ($review->status === ArticleProductReviewStatus::Published && $review->wp_comment_id !== null)
            || $review->status === ArticleProductReviewStatus::Publishing
        ) {
            return;
        }

        if ($review->next_retry_at !== null && $review->next_retry_at->isFuture()) {
            $this->release(max(1, (int) $review->next_retry_at->diffInSeconds(now())));

            return;
        }

        $article = SeoArticle::query()->find($this->articleId);
        if (! $article instanceof SeoArticle) {
            Log::warning('product_review.publish_dispatch.article_missing', [
                'review_id' => $this->reviewId,
                'article_id' => $this->articleId,
            ]);

            return;
        }

        $wpPostId = (int) ($article->wp_post_id ?? $review->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            $review->forceFill([
                'status' => ArticleProductReviewStatus::PendingArticle->value,
                'scheduled_at' => null,
            ])->save();

            return;
        }

        Log::info('product_review.publish_started', [
            'review_id' => $this->reviewId,
            'article_id' => $this->articleId,
            'attempt' => $this->attempts(),
            'queue' => $this->queue ?? 'automation-external',
            'selected_delay_seconds' => (int) ($review->selected_delay_seconds ?? 0),
            'scheduled_at' => $review->scheduled_at?->toIso8601String(),
        ]);

        $context = [
            'product_review_delay_applied' => true,
        ];
        if ($this->actorId !== null && $this->actorId > 0) {
            $context['actor_id'] = $this->actorId;
        }

        $dispatchService->dispatchAndRun(
            $review,
            $article,
            [
                'article_id' => $this->articleId,
                'site_id' => $this->siteId > 0 ? $this->siteId : (int) $review->site_id,
                'connection_id' => $this->connectionId > 0 ? $this->connectionId : (int) $review->connection_id,
                'review_id' => $this->reviewId,
                'wp_post_id' => $wpPostId,
                'publish_intent' => $this->publishIntent,
                'configured_max_delay_minutes' => (int) ($review->configured_max_delay_minutes
                    ?? $this->settingsSnapshot['max_delay_time']
                    ?? 0),
                'selected_delay_seconds' => (int) ($review->selected_delay_seconds ?? 0),
                'scheduled_at' => $review->scheduled_at?->toIso8601String(),
                'product_review_delay_applied' => true,
            ],
            $context,
        );
    }
}
