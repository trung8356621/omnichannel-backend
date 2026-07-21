<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\PublishWordPressCommentReviewHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\QueuePendingProductReviewsHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\ScheduleGeneratedProductReviewsHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Enums\ArticleProductReviewStatus;
use App\Addons\SeoContentAi\Enums\ProductReviewPublishIntent;
use App\Addons\SeoContentAi\Jobs\DispatchScheduledProductReviewPublishJob;
use App\Addons\SeoContentAi\Services\ProductReview\ArticleProductReviewStoreService;
use App\Addons\SeoContentAi\Services\ProductReview\PendingProductReviewReconciler;
use App\Addons\SeoContentAi\Services\ProductReview\ProductReviewDelaySettings;
use App\Addons\SeoContentAi\Services\ProductReview\ProductReviewPublishDispatchService;
use App\Addons\SeoContentAi\Services\ProductReview\WordPressCommentReviewPayloadFactory;
use App\Addons\SeoContentAi\Services\ProductReview\WordPressCommentReviewPublisher;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Services\WordPressCommentReviewService;
use PHPUnit\Framework\TestCase;

/**
 * Product review automation invariants (source-level + pure helpers).
 */
final class ProductReviewAutomationPublishTest extends TestCase
{
    public function test_action_and_event_enums_registered(): void
    {
        self::assertSame('wordpress.comment_review.publish', AutomationActionCode::WordpressCommentReviewPublish->value);
        self::assertSame('article.product_reviews.queue_pending', AutomationActionCode::ArticleProductReviewsQueuePending->value);
        self::assertSame('article.product_reviews.schedule_generated', AutomationActionCode::ArticleProductReviewsScheduleGenerated->value);
        self::assertSame('article.product_reviews_generated', BusinessEventName::ArticleProductReviewsGenerated->value);
        self::assertSame('article.product_review_publish_requested', BusinessEventName::ArticleProductReviewPublishRequested->value);
    }

    public function test_handler_classes_exist(): void
    {
        self::assertTrue(class_exists(PublishWordPressCommentReviewHookAction::class));
        self::assertTrue(class_exists(QueuePendingProductReviewsHookAction::class));
        self::assertTrue(class_exists(ScheduleGeneratedProductReviewsHookAction::class));
        self::assertTrue(class_exists(PendingProductReviewReconciler::class));
        self::assertTrue(class_exists(DispatchScheduledProductReviewPublishJob::class));
        self::assertTrue(class_exists(ArticleProductReviewStoreService::class));
        self::assertTrue(class_exists(WordPressCommentReviewPublisher::class));
        self::assertTrue(class_exists(WordPressCommentReviewPayloadFactory::class));
        self::assertTrue(class_exists(ProductReviewPublishDispatchService::class));
    }

    public function test_status_lifecycle_includes_scheduled(): void
    {
        self::assertTrue(ArticleProductReviewStatus::Scheduled->isPublishable());
        self::assertTrue(ArticleProductReviewStatus::PendingArticle->isPublishable());
        self::assertTrue(ArticleProductReviewStatus::Failed->isPublishable());
        self::assertFalse(ArticleProductReviewStatus::Published->isPublishable());
        self::assertFalse(ArticleProductReviewStatus::Publishing->isPublishable());
        self::assertSame('generated_review', ProductReviewPublishIntent::GeneratedReview->value);
        self::assertSame('publish_after_article', ProductReviewPublishIntent::PublishAfterArticle->value);
    }

    public function test_max_delay_time_picker(): void
    {
        self::assertSame(0, ProductReviewDelaySettings::pickDelaySeconds(0));
        self::assertSame(5, ProductReviewDelaySettings::resolveMaxDelayMinutes(['max_delay_time' => 5]));
        self::assertSame(5, ProductReviewDelaySettings::resolveMaxDelayMinutes(['delay_max_after_minutes' => 5]));
        self::assertSame(0, ProductReviewDelaySettings::resolveMaxDelayMinutes(['max_delay_time' => 0], 0));

        for ($i = 0; $i < 20; $i++) {
            $seconds = ProductReviewDelaySettings::pickDelaySeconds(5);
            self::assertGreaterThanOrEqual(60, $seconds);
            self::assertLessThanOrEqual(300, $seconds);
            self::assertNotEquals(5, $seconds, 'max_delay_time=5 must not become 5 seconds');
        }
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

    public function test_store_service_emits_generated_only_not_direct_publish_fanout(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ProductReview/ArticleProductReviewStoreService.php',
        );
        self::assertStringNotContainsString('WordPressGateway', $source);
        self::assertStringNotContainsString('pushToWordPress', $source);
        self::assertStringContainsString('ArticleProductReviewsGenerated', $source);
        self::assertStringNotContainsString('ArticleProductReviewPublishRequested', $source);
    }

    public function test_comment_review_service_cutover_no_direct_push(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/WordPressCommentReviewService.php',
        );
        self::assertStringContainsString('ArticleProductReviewStoreService', $source);
        self::assertStringNotContainsString('pushToWordPress', $source);
        self::assertTrue(class_exists(WordPressCommentReviewService::class));
    }

    public function test_h_article_sync_payload_has_no_virtual_comments(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/WordPressArticleSyncService.php',
        );
        self::assertStringNotContainsString("payload['virtual_comments']", $source);
        self::assertStringNotContainsString('pending_virtual_comments', $source);
        self::assertTrue(class_exists(WordPressArticleSyncService::class));
    }

    public function test_default_rules_seeded_for_review_publish(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Automation/BusinessHook/Seed/AutomationDefaultRulesSeeder.php',
        );
        self::assertStringContainsString('publish-generated-product-reviews-to-wordpress', $source);
        self::assertStringContainsString('publish-pending-product-reviews-after-article-sync', $source);
        self::assertStringContainsString('execute-wordpress-comment-review-publish', $source);
        self::assertStringContainsString('ArticleProductReviewsGenerated', $source);
        self::assertStringContainsString('ArticleProductReviewsScheduleGenerated', $source);
        self::assertStringContainsString('max_delay_time', $source);
        self::assertStringContainsString('WordpressSynced', $source);
        self::assertStringContainsString("'run_mode' => 'sync'", $source);
        self::assertStringContainsString('execute-wordpress-comment-review-publish', $source);
    }

    public function test_delayed_job_runs_publish_dispatch_service(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Jobs/DispatchScheduledProductReviewPublishJob.php',
        );
        self::assertStringContainsString('ProductReviewPublishDispatchService', $source);
        self::assertStringContainsString('dispatchAndRun', $source);
    }

    public function test_publisher_allows_queue_automation_acl_bypass(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ProductReview/WordPressCommentReviewPublisher.php',
        );
        self::assertStringContainsString('SeoQueueContext::isWpSyncFromQueue()', $source);
    }

    public function test_side_effect_guard_allows_comment_review_publish_action(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/WordPress/SideEffect/WordPressSideEffectGuard.php',
        );
        self::assertStringContainsString('WordpressCommentReviewPublish', $source);
        self::assertStringContainsString('comment_review.publish', $source);
    }

    public function test_ui_has_no_publish_now_or_cancel(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/ArticleReviewsTab.jsx',
        );
        self::assertStringNotContainsString('Publish now', $source);
        self::assertStringNotContainsString('onPublishReview', $source);
        self::assertStringNotContainsString('onCancelReview', $source);
        self::assertStringNotContainsString('Pending schedule', $source);
        self::assertStringContainsString('Waiting for article sync', $source);
        self::assertStringContainsString('Waiting for Automation', $source);
        self::assertStringContainsString('Scheduled at', $source);
    }

    public function test_j_publisher_validates_scope_keys_in_hook_action(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Automation/BusinessHook/Actions/PublishWordPressCommentReviewHookAction.php',
        );
        self::assertStringContainsString('REVIEW_SCOPE_MISMATCH', $source);
        self::assertStringContainsString('ARTICLE_SITE_MISMATCH', $source);
        self::assertStringContainsString('SKIPPED_WAITING_FOR_ARTICLE', $source);
        self::assertStringContainsString('LOCAL_FINALIZE_FAILED', $source);
    }

    public function test_execution_service_does_not_delay_scheduler_actions(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Automation/BusinessHook/Services/AutomationExecutionService.php',
        );
        self::assertStringContainsString('isProductReviewScheduler', $source);
        self::assertStringContainsString('article.product_reviews.schedule_generated', $source);
        self::assertStringContainsString('product_review_delay_applied', $source);
        self::assertStringContainsString('max_delay_time', $source);
    }

    public function test_scheduler_sets_scheduled_only_after_dispatch(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ProductReview/PendingProductReviewReconciler.php',
        );
        self::assertStringContainsString('FailedDispatch', $source);
        self::assertStringContainsString('product_review.publish_scheduled', $source);
        self::assertStringContainsString('AutomationQueueName::External', $source);
        $dispatchPos = strpos($source, 'DispatchScheduledProductReviewPublishJob::dispatch');
        $markScheduledPos = strpos($source, "status' => ArticleProductReviewStatus::Scheduled->value");
        self::assertNotFalse($dispatchPos);
        self::assertNotFalse($markScheduledPos);
        self::assertGreaterThan($dispatchPos, (int) $markScheduledPos);
    }

    public function test_reconcile_service_and_endpoint_exist(): void
    {
        self::assertTrue(class_exists(\App\Addons\SeoContentAi\Services\ProductReview\ProductReviewReconciliationService::class));
        self::assertTrue(class_exists(\App\Addons\SeoContentAi\Services\ProductReview\LegacyProductReviewStateNormalizer::class));
        self::assertTrue(class_exists(\App\Addons\SeoContentAi\Http\Controllers\ArticleProductReviewReconcileController::class));
        self::assertTrue(class_exists(\App\Addons\SeoContentAi\Console\ProductReviewsDiagnoseStuckCommand::class));
        self::assertTrue(ArticleProductReviewStatus::FailedDispatch->isPublishable());
    }

    public function test_reconcile_command_registered(): void
    {
        self::assertTrue(class_exists(\App\Addons\SeoContentAi\Console\ProductReviewsReconcilePendingCommand::class));
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Console/ProductReviewsReconcilePendingCommand.php',
        );
        self::assertStringContainsString('product-reviews:reconcile-pending', $source);
        self::assertStringContainsString('ProductReviewReconciliationService', $source);
        self::assertStringContainsString('--force', $source);
    }
}
