<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard: last_manual_saved_at / last_synced_at không lấy từ updated_at.
 */
final class ArticleLastSavedTimestampWiringTest extends TestCase
{
    public function test_update_article_content_action_only_touches_manual_for_editor_origin(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/Automation/Actions/Article/UpdateArticleContentAction.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString('ArticleLastSavedTimestampService', $source);
        self::assertStringContainsString('shouldTouchManualFromOrigin', $source);
        self::assertStringContainsString('touchManualSaved', $source);
    }

    public function test_wordpress_sync_success_touches_synced_timestamp(): void
    {
        $sync = file_get_contents(
            dirname(__DIR__, 2).'/Services/WordPressArticleSyncService.php'
        );
        $domain = file_get_contents(
            dirname(__DIR__, 2).'/Services/SyncDomainContentService.php'
        );
        $pull = file_get_contents(
            dirname(__DIR__, 2).'/Services/WordPressArticleContentService.php'
        );

        self::assertNotFalse($sync);
        self::assertNotFalse($domain);
        self::assertNotFalse($pull);
        self::assertStringContainsString('touchSynced', $sync);
        self::assertStringContainsString('touchSynced', $domain);
        self::assertStringContainsString('touchSynced', $pull);
    }

    public function test_migration_adds_dedicated_columns_without_updated_at_backfill(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_07_24_100000_add_last_saved_timestamps_to_articles_table.php'
        );
        self::assertNotFalse($migration);
        self::assertStringContainsString("timestamp('last_manual_saved_at')", $migration);
        self::assertStringContainsString("timestamp('last_synced_at')", $migration);
        self::assertStringNotContainsString('updated_at', $migration);
        self::assertStringNotContainsString('backfill', strtolower($migration));
    }

    public function test_manual_save_origins_exclude_automation(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleLastSavedTimestampService.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString("'article_editor'", $source);
        self::assertStringNotContainsString('migration.project_article_content_update', $source);
    }
}
