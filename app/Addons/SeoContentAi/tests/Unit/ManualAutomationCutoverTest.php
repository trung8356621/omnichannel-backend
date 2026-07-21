<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationQueueName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use App\Addons\SeoContentAi\Jobs\ManualWordPressSyncJob;
use App\Addons\SeoContentAi\Jobs\SyncArticleToWordPressFromQueueJob;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\WordPress\ManualSyncContext;
use App\Addons\SeoContentAi\Services\WordPress\WordPressManualSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Manual WordPress = dedicated service + ManualSyncContext; automatic = Automation Rule.
 */
final class ManualAutomationCutoverTest extends TestCase
{
    public function test_manual_sync_context_and_job_exist(): void
    {
        $base = dirname(__DIR__, 2);
        self::assertFileExists($base.'/Services/WordPress/ManualSyncContext.php');
        self::assertFileExists($base.'/Jobs/ManualWordPressSyncJob.php');
        self::assertSame('wordpress.article.sync', AutomationActionCode::WordpressArticleSync->value);
        self::assertTrue(class_exists(ManualSyncContext::class));
        self::assertTrue(class_exists(WordPressManualSyncService::class));
        self::assertTrue(class_exists(ManualWordPressSyncJob::class));
    }

    public function test_manual_sync_service_uses_domain_job_not_automation_dispatcher(): void
    {
        $base = dirname(__DIR__, 2);
        $source = (string) file_get_contents($base.'/Services/WordPress/WordPressManualSyncService.php');
        self::assertStringContainsString('ManualWordPressSyncJob', $source);
        self::assertStringContainsString('ManualSyncContext', $source);
        self::assertStringNotContainsString('ManualAutomationDispatcher', $source);
        self::assertStringNotContainsString('AutomationAvailabilityGate', $source);
        self::assertStringNotContainsString('SyncArticleToWordPressFromQueueJob', $source);
        self::assertStringNotContainsString('article.completed', $source);
    }

    public function test_manual_job_emits_wordpress_synced_with_manual_origin(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Jobs/ManualWordPressSyncJob.php',
        );
        self::assertStringContainsString('toSideEffectContext', $source);
        self::assertStringContainsString('wordpressSynced', $source);
        self::assertStringContainsString("'origin' => 'manual'", $source);
        self::assertStringNotContainsString('ManualAutomationDispatcher', $source);
        self::assertStringNotContainsString('ProductReviewPostSyncReconciler', $source);
    }

    public function test_editor_controller_uses_manual_service(): void
    {
        $base = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($base.'/Http/Controllers/ArticleEditorSyncController.php');
        self::assertStringContainsString('WordPressManualSyncService', $controller);
        self::assertStringContainsString('article_editor.sync_wordpress', $controller);
        self::assertStringNotContainsString('SyncArticleToWordPressFromQueueJob', $controller);
        self::assertStringNotContainsString('ManualAutomationDispatcher', $controller);
    }

    public function test_legacy_seo_wp_job_is_deprecated_shell(): void
    {
        $base = dirname(__DIR__, 2);
        $job = (string) file_get_contents($base.'/Jobs/SyncArticleToWordPressFromQueueJob.php');
        self::assertStringContainsString('DEPRECATED', $job);
        self::assertStringNotContainsString('syncFromEditorBundle', $job);

        $queued = new SyncArticleToWordPressFromQueueJob(1);
        self::assertSame(ArticleWpSyncQueueService::QUEUE_NAME, $queued->queue);
    }

    public function test_queue_service_blocks_legacy_dispatch(): void
    {
        $base = dirname(__DIR__, 2);
        $source = (string) file_get_contents($base.'/Services/ArticleWpSyncQueueService.php');
        self::assertStringContainsString('Legacy seo queue orchestration removed', $source);
        self::assertStringContainsString('dispatch_blocked', $source);
    }

    public function test_wordpress_action_is_automatic_only(): void
    {
        $base = dirname(__DIR__, 2);
        $provider = (string) file_get_contents($base.'/Automation/Modules/WordPress/WordPressAutomationModuleProvider.php');
        self::assertStringContainsString('supportsManualTrigger: false', $provider);
        self::assertStringContainsString('manualEnabled: false', $provider);
        self::assertStringContainsString('AutomationQueueName::External', $provider);
        self::assertStringContainsString('WordPressManualSyncService', $provider);
    }

    public function test_side_effect_guard_allows_manual_context(): void
    {
        $base = dirname(__DIR__, 2);
        $guard = (string) file_get_contents($base.'/Services/WordPress/SideEffect/WordPressSideEffectGuard.php');
        self::assertStringContainsString('assertManual', $guard);
        self::assertStringNotContainsString('ManualWordPressContext deprecated', $guard);
        self::assertStringContainsString('automation or manual', $guard);
    }

    public function test_execute_rule_job_default_queue_critical(): void
    {
        $job = new ExecuteAutomationRuleJob(99);
        self::assertSame(AutomationQueueName::Critical->value, $job->queue);
    }
}
