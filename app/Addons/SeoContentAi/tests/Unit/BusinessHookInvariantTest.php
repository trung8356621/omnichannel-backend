<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\SyncArticleToWordPressHookAction;
use Tests\TestCase;

/**
 * Business Hook wiring invariants — static source assertions, no DB.
 */
final class BusinessHookInvariantTest extends TestCase
{
    public function test_production_callers_do_not_reference_wp_sync_services(): void
    {
        $paths = [
            dirname(__DIR__, 2).'/Services/CreateArticlesFromTaskService.php',
            dirname(__DIR__, 2).'/Services/SeoProjectWorkflowRunService.php',
            dirname(__DIR__, 2).'/Services/ArticleScheduleReconcileService.php',
        ];

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString('WordPressArticleSyncService', $source);
            self::assertStringNotContainsString('ArticleWpSyncQueueService', $source);
            self::assertStringNotContainsString('SyncArticleToWordPressFromQueueJob', $source);
        }
    }

    public function test_sync_hook_action_references_wordpress_sync_service(): void
    {
        $hook = (string) file_get_contents(
            dirname(__DIR__, 2).'/Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php',
        );

        self::assertStringContainsString('WordPressArticleSyncService', $hook);
        self::assertTrue(class_exists(SyncArticleToWordPressHookAction::class));
    }

    public function test_seeded_rules_disabled_in_seeder_source(): void
    {
        $seeder = (string) file_get_contents(
            dirname(__DIR__, 2).'/Automation/BusinessHook/Seed/AutomationDefaultRulesSeeder.php',
        );

        foreach (['sync-article-to-wordpress', 'notify-workflow-failure', 'dispatch-publish-request'] as $code) {
            self::assertMatchesRegularExpression(
                "/code:\s*'{$code}'[\s\S]*?'is_enabled'\s*=>\s*false/",
                $seeder,
                "Expected rule {$code} to be disabled by default.",
            );
        }
    }
}
