<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationQueueName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\ManualAutomationDispatcher;
use App\Addons\SeoContentAi\Jobs\SyncArticleToWordPressFromQueueJob;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\WordPress\WordPressManualSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Manual trigger = Automation Engine trigger_type, never legacy seo orchestration.
 */
final class ManualAutomationCutoverTest extends TestCase
{
    public function test_manual_dispatcher_and_wordpress_action_exist(): void
    {
        $base = dirname(__DIR__, 2);
        self::assertFileExists($base.'/Automation/BusinessHook/Services/ManualAutomationDispatcher.php');
        self::assertSame('wordpress.article.sync', AutomationActionCode::WordpressArticleSync->value);
        self::assertTrue(class_exists(ManualAutomationDispatcher::class));
        self::assertTrue(class_exists(WordPressManualSyncService::class));
    }

    public function test_manual_sync_service_uses_dispatcher_not_legacy_job(): void
    {
        $base = dirname(__DIR__, 2);
        $source = (string) file_get_contents($base.'/Services/WordPress/WordPressManualSyncService.php');
        self::assertStringContainsString('ManualAutomationDispatcher', $source);
        self::assertStringContainsString('WordpressArticleSync', $source);
        self::assertStringNotContainsString('SyncArticleToWordPressFromQueueJob', $source);
        self::assertStringNotContainsString('ManualWordPressContext', $source);
    }

    public function test_wordpress_availability_gate_exists(): void
    {
        $base = dirname(__DIR__, 2);
        self::assertFileExists($base.'/Automation/BusinessHook/Services/AutomationAvailabilityGate.php');
        $dispatcher = (string) file_get_contents($base.'/Automation/BusinessHook/Services/ManualAutomationDispatcher.php');
        self::assertStringContainsString('checkManual', $dispatcher);
        self::assertStringContainsString('AutomationAvailabilityGate', $dispatcher);
    }

    public function test_editor_controller_uses_manual_automation_path(): void
    {
        $base = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($base.'/Http/Controllers/ArticleEditorSyncController.php');
        self::assertStringContainsString('WordPressManualSyncService', $controller);
        self::assertStringContainsString('article_editor.sync_wordpress', $controller);
        self::assertStringNotContainsString('bypass', strtolower($controller));
        self::assertStringNotContainsString('SyncArticleToWordPressFromQueueJob', $controller);
        self::assertStringNotContainsString('contextFromAuth', $controller);
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

    public function test_wordpress_action_default_queue_is_automation_external(): void
    {
        $base = dirname(__DIR__, 2);
        $provider = (string) file_get_contents($base.'/Automation/Modules/WordPress/WordPressAutomationModuleProvider.php');
        self::assertStringContainsString('supportsManualTrigger: true', $provider);
        self::assertStringContainsString('AutomationQueueName::External', $provider);
        self::assertStringContainsString('manualPermission: \'wordpress.sync\'', $provider);
    }

    public function test_side_effect_guard_rejects_manual_context(): void
    {
        $base = dirname(__DIR__, 2);
        $guard = (string) file_get_contents($base.'/Services/WordPress/SideEffect/WordPressSideEffectGuard.php');
        self::assertStringContainsString('ManualWordPressContext deprecated', $guard);
        self::assertStringContainsString('AutomationWordPressContext', $guard);
    }

    public function test_execute_rule_job_can_override_queue_for_manual(): void
    {
        $job = new ExecuteAutomationRuleJob(99);
        self::assertSame(AutomationQueueName::Critical->value, $job->queue);
        $job->onQueue(AutomationQueueName::External->value);
        self::assertSame(AutomationQueueName::External->value, $job->queue);
    }
}
