<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\PublishWordPressCommentReviewHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\QueuePendingProductReviewsHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\ScheduleGeneratedProductReviewsHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Enums\ArticleProductReviewStatus;
use App\Addons\SeoContentAi\Jobs\DispatchScheduledProductReviewPublishJob;
use App\Addons\SeoContentAi\Services\ProductReview\ArticleProductReviewStoreService;
use App\Addons\SeoContentAi\Services\ProductReview\WordPressCommentReviewPayloadFactory;
use App\Addons\SeoContentAi\Services\WordPress\SyncArticleToWordPressPipeline;
use PHPUnit\Framework\TestCase;

/**
 * Product review cutover: legacy handlers noop; pipeline is sole owner.
 */
final class ProductReviewAutomationPublishTest extends TestCase
{
    public function test_action_and_event_enums_still_registered_for_history(): void
    {
        self::assertSame('wordpress.comment_review.publish', AutomationActionCode::WordpressCommentReviewPublish->value);
        self::assertSame('article.product_reviews.queue_pending', AutomationActionCode::ArticleProductReviewsQueuePending->value);
        self::assertSame('article.product_reviews.schedule_generated', AutomationActionCode::ArticleProductReviewsScheduleGenerated->value);
        self::assertSame('article.product_reviews_generated', BusinessEventName::ArticleProductReviewsGenerated->value);
        self::assertSame('wordpress.article.sync', AutomationActionCode::WordpressArticleSync->value);
    }

    public function test_legacy_handlers_exist_as_safe_noops(): void
    {
        self::assertTrue(class_exists(PublishWordPressCommentReviewHookAction::class));
        self::assertTrue(class_exists(QueuePendingProductReviewsHookAction::class));
        self::assertTrue(class_exists(ScheduleGeneratedProductReviewsHookAction::class));
        self::assertTrue(class_exists(DispatchScheduledProductReviewPublishJob::class));
        self::assertTrue(class_exists(SyncArticleToWordPressPipeline::class));
        self::assertTrue(class_exists(ArticleProductReviewStoreService::class));
    }

    public function test_new_status_lifecycle(): void
    {
        self::assertTrue(ArticleProductReviewStatus::Pending->isPublishable());
        self::assertTrue(ArticleProductReviewStatus::Failed->isPublishable());
        self::assertFalse(ArticleProductReviewStatus::Reviewed->isPublishable());
        self::assertFalse(ArticleProductReviewStatus::Syncing->isPublishable());
        self::assertTrue(ArticleProductReviewStatus::Published->isReviewed());
    }

    public function test_payload_factory_includes_omi_metadata(): void
    {
        $factory = new WordPressCommentReviewPayloadFactory();
        $review = new \App\Addons\SeoContentAi\Models\ArticleProductReview();
        $review->forceFill([
            'article_id' => 7,
            'author_name' => 'Lan',
            'content' => 'Túi đẹp',
            'idempotency_key' => 'abc',
            'rating' => 5,
            'review_date' => '2026-07-01 10:00:00',
        ]);
        $review->id = 42;

        $item = $factory->makeItem($review);
        self::assertSame(42, $item['_omi_review_id']);
        self::assertSame('abc', $item['_omi_idempotency_key']);
        self::assertSame(7, $item['_omi_article_id']);
        self::assertSame(-(1000 * 99 + 2), $factory->syntheticWpCommentId(99, 2));
    }

    public function test_store_service_creates_pending_only(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ProductReview/ArticleProductReviewStoreService.php',
        );
        self::assertStringContainsString('ArticleProductReviewStatus::Pending', $source);
        self::assertStringNotContainsString('ArticleProductReviewsGenerated', $source);
        self::assertStringNotContainsString('WordPressCommentReviewPublisher', $source);
    }

    public function test_single_pipeline_owner_in_sync_action(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php',
        );
        self::assertStringContainsString('SyncArticleToWordPressPipeline', $source);
        self::assertStringNotContainsString('PendingProductReviewReconciler', $source);
        self::assertStringNotContainsString('WordPressCommentReviewPublisher', $source);
    }

    public function test_legacy_publish_action_skips(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Automation/BusinessHook/Actions/PublishWordPressCommentReviewHookAction.php',
        );
        self::assertStringContainsString('skipped', $source);
        self::assertStringContainsString('owned_by_wordpress_article_sync_pipeline', $source);
    }
}
