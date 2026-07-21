<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductReviewArticleSyncIsolationTest extends TestCase
{
    public function test_article_sync_service_does_not_orchestrate_review_publish(): void
    {
        $path = dirname(__DIR__, 2).'/Services/WordPressArticleSyncService.php';
        $source = (string) file_get_contents($path);

        self::assertStringNotContainsString('ArticleProductReview', $source);
        self::assertStringNotContainsString('WordPressCommentReviewPublisher', $source);
        self::assertStringNotContainsString('product_reviews', $source);
        self::assertStringNotContainsString("['virtual_comments']", $source);
    }

    public function test_sync_hook_action_emits_wordpress_synced_not_review_publish(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php',
        );
        self::assertStringContainsString('WordpressSynced', $source);
        self::assertStringNotContainsString('CommentReview', $source);
        self::assertStringNotContainsString('virtual_comments', $source);
    }

    public function test_orchestrator_has_no_virtual_comments(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleEditorSyncOrchestrator.php',
        );
        self::assertStringNotContainsString('virtual_comments', $source);
        self::assertStringNotContainsString('WordPressCommentReviewPublisher', $source);
        self::assertStringContainsString('wordpressSynced', $source);
        self::assertStringContainsString('ProductReviewPostSyncReconciler', $source);
    }
}
