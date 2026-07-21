<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ProductReview;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter;
use App\Addons\SeoContentAi\Enums\ArticleProductReviewStatus;
use App\Addons\SeoContentAi\Models\ArticleProductReview;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\CommentReviewPayloadParser;
use App\Addons\SeoContentAi\Support\CommentReviewRatingAssigner;
use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use App\Addons\SeoContentAi\Services\VirtualCommentService;
use Illuminate\Support\Facades\DB;

/**
 * Normalize AI output → local article_product_reviews → emit generated events.
 * Never calls WordPress.
 */
final class ArticleProductReviewStoreService
{
    public function __construct(
        private readonly CommentReviewPayloadParser $parser,
        private readonly BusinessHookEmitter $emitter,
        private readonly VirtualCommentService $virtualComments,
        private readonly CommentReviewRatingAssigner $ratingAssigner,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     created_count: int,
     *     review_ids: list<int>,
     *     automation_enabled?: bool,
     *     has_wp_post_id?: bool
     * }
     */
    public function storeFromAiOutput(SeoArticle $article, string $aiOutput, string $source = 'ai_generated'): array
    {
        $items = $this->parser->parse($aiOutput);
        if ($items === []) {
            return [
                'success' => false,
                'message' => 'Không parse được bình luận/review từ kết quả AI.',
                'created_count' => 0,
                'review_ids' => [],
            ];
        }

        return $this->storeItems($article, $items, $source);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *     success: bool,
     *     message: string,
     *     created_count: int,
     *     review_ids: list<int>,
     *     automation_enabled?: bool,
     *     has_wp_post_id?: bool
     * }
     */
    public function storeItems(SeoArticle $article, array $items, string $source = 'ai_generated'): array
    {
        $connectionId = (int) (SeoConnectionContext::current()?->id ?? 0);
        if ($connectionId <= 0) {
            return [
                'success' => false,
                'message' => 'Thiếu SEO connection context — không lưu được product review.',
                'created_count' => 0,
                'review_ids' => [],
            ];
        }

        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa gắn domain (site_id).',
                'created_count' => 0,
                'review_ids' => [],
            ];
        }

        $wpPostId = (int) ($article->wp_post_id ?? 0);
        $status = $wpPostId > 0
            ? ArticleProductReviewStatus::PendingPublish
            : ArticleProductReviewStatus::PendingArticle;

        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';
        $createdIds = [];

        DB::connection('omi_seo_ai')->transaction(function () use (
            $article,
            $items,
            $source,
            $connectionId,
            $siteId,
            $wpPostId,
            $status,
            $isProduct,
            &$createdIds,
        ): void {
            foreach (array_values($items) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $content = trim((string) ($item['content'] ?? $item['comment'] ?? $item['review'] ?? ''));
                if ($content === '') {
                    continue;
                }

                $author = trim((string) ($item['author'] ?? $item['author_name'] ?? 'Khách mua hàng'));
                if ($author === '') {
                    $author = 'Khách mua hàng';
                }

                $email = trim((string) ($item['email'] ?? $item['author_email'] ?? ''));
                $email = $email !== '' ? $email : null;

                $explicitRating = null;
                if (isset($item['rating']) && is_numeric($item['rating'])) {
                    $explicitRating = (int) $item['rating'];
                }
                $rating = $isProduct
                    ? $this->ratingAssigner->resolve($explicitRating, $index)
                    : ($explicitRating !== null ? max(1, min(5, $explicitRating)) : null);

                $reviewDate = trim((string) ($item['date'] ?? ''));
                $contentHash = hash('sha256', mb_strtolower($author)."\0".mb_strtolower($content)."\0".(string) $rating);
                $idempotencyKey = hash(
                    'sha256',
                    implode('|', [
                        $connectionId,
                        (int) $article->id,
                        $contentHash,
                        $source,
                        $index,
                        now()->format('YmdHis'),
                    ]),
                );

                $review = ArticleProductReview::query()->create([
                    'article_id' => (int) $article->id,
                    'site_id' => $siteId,
                    'connection_id' => $connectionId,
                    'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                    'author_name' => $author,
                    'author_email' => $email,
                    'content' => $content,
                    'rating' => $rating,
                    'review_date' => $reviewDate !== '' ? $reviewDate : now(),
                    'source' => $source,
                    'status' => $status,
                    'publish_attempts' => 0,
                    'content_hash' => $contentHash,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $createdIds[] = (int) $review->id;
            }

            // Mirror legacy meta for older readers until UI fully cut over.
            $mirrorItems = ArticleProductReview::query()
                ->where('article_id', (int) $article->id)
                ->whereIn('status', [
                    ArticleProductReviewStatus::PendingArticle->value,
                    ArticleProductReviewStatus::PendingPublish->value,
                    ArticleProductReviewStatus::Publishing->value,
                    ArticleProductReviewStatus::Published->value,
                    ArticleProductReviewStatus::Failed->value,
                ])
                ->orderBy('id')
                ->get()
                ->map(static function (ArticleProductReview $review): array {
                    $row = [
                        'author' => $review->author_name,
                        'content' => $review->content,
                        'date' => $review->review_date?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                    ];
                    if ($review->rating !== null) {
                        $row['rating'] = (int) $review->rating;
                    }

                    return $row;
                })
                ->all();

            $this->virtualComments->storeOnArticle($article, $mirrorItems, $isProduct);
        });

        if ($createdIds === []) {
            return [
                'success' => false,
                'message' => 'Không có mục bình luận/review hợp lệ để lưu.',
                'created_count' => 0,
                'review_ids' => [],
            ];
        }

        $article = $article->fresh() ?? $article;
        $automationEnabled = $this->isPublishAutomationEnabled();

        $this->emitter->emit(BusinessEventName::ArticleProductReviewsGenerated, $article, [
            'article_id' => (int) $article->id,
            'site_id' => $siteId,
            'connection_id' => $connectionId,
            'review_ids' => $createdIds,
            'review_count' => count($createdIds),
            'automation_enabled' => $automationEnabled,
            'has_wp_post_id' => $wpPostId > 0,
        ]);

        $kind = $isProduct ? 'review ảo' : 'bình luận ảo';
        $message = sprintf('Đã lưu %d %s local.', count($createdIds), $kind);
        if ($automationEnabled && $wpPostId > 0) {
            $message = sprintf(
                'Đã tạo %d review. Hệ thống sẽ tự động đăng lên WordPress trong vòng vài phút (theo Automation).',
                count($createdIds),
            );
        } elseif ($automationEnabled) {
            $message = sprintf(
                'Đã tạo %d review. Review sẽ tự động được đăng sau khi bài viết đồng bộ WordPress.',
                count($createdIds),
            );
        } else {
            $message = sprintf(
                'Đã tạo %d review nhưng Automation đăng review đang tắt. Review hiện được lưu cục bộ.',
                count($createdIds),
            );
        }

        return [
            'success' => true,
            'message' => $message,
            'created_count' => count($createdIds),
            'review_ids' => $createdIds,
            'automation_enabled' => $automationEnabled,
            'has_wp_post_id' => $wpPostId > 0,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForEditor(SeoArticle $article): array
    {
        $rows = ArticleProductReview::query()
            ->where('article_id', (int) $article->id)
            ->where('status', '!=', ArticleProductReviewStatus::Cancelled->value)
            ->orderBy('id')
            ->get();

        if ($rows->isNotEmpty()) {
            $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';
            if ($isProduct) {
                foreach ($rows->values() as $index => $review) {
                    /** @var ArticleProductReview $review */
                    if ($review->rating !== null) {
                        continue;
                    }
                    $review->rating = $this->ratingAssigner->resolve(null, (int) $index);
                    $review->save();
                }
            }

            return $rows->map(static fn (ArticleProductReview $r): array => $r->toEditorArray())->all();
        }

        return $this->virtualComments->getForEditor($article);
    }

    public function isPublishAutomationEnabled(): bool
    {
        try {
            return \App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRule::query()
                ->whereIn('code', [
                    'publish-generated-product-reviews-to-wordpress',
                    'publish-pending-product-reviews-after-article-sync',
                ])
                ->where('is_enabled', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Queue pending reviews after article has wp_post_id (via reconciler — no direct WP).
     *
     * @return list<int> review ids queued
     */
    public function queuePendingForArticle(SeoArticle $article, string $publishIntent = 'publish_after_article'): array
    {
        $result = app(PendingProductReviewReconciler::class)->reconcileForArticle(
            article: $article,
            settings: ['max_delay_time' => ProductReviewDelaySettings::DEFAULT_MINUTES],
            reviewIds: null,
            publishIntent: $publishIntent,
            actorId: null,
            dryRun: false,
        );

        return $result->queuedReviewIds;
    }
}
