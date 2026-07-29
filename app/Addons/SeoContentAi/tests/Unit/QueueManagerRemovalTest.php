<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Filament\Pages\AutomationOperationsDashboard;
use App\Addons\SeoContentAi\Filament\Resources\AutomationExecutionResource;
use App\Addons\SeoContentAi\Filament\Resources\AutomationRuleResource;
use PHPUnit\Framework\TestCase;

/**
 * Queue Manager UI removed: Laravel Queue stays backend-only.
 */
final class QueueManagerRemovalTest extends TestCase
{
    public function test_queue_manager_page_and_service_are_gone(): void
    {
        $base = dirname(__DIR__, 2);

        self::assertFileDoesNotExist($base.'/Filament/Pages/SeoQueueManager.php');
        self::assertFileDoesNotExist($base.'/resources/views/filament/pages/seo-queue-manager.blade.php');
        self::assertFileDoesNotExist($base.'/resources/views/components/global-queue-worker-alert.blade.php');
        self::assertFileDoesNotExist($base.'/Services/SeoQueueControlService.php');
        self::assertFalse(class_exists(\App\Addons\SeoContentAi\Filament\Pages\SeoQueueManager::class));
        self::assertFalse(class_exists(\App\Addons\SeoContentAi\Services\SeoQueueControlService::class));
    }

    public function test_automation_nav_targets_remain_registered(): void
    {
        $base = dirname(__DIR__, 2);

        self::assertTrue(class_exists(AutomationRuleResource::class));
        self::assertTrue(class_exists(AutomationExecutionResource::class));
        self::assertTrue(class_exists(AutomationOperationsDashboard::class));
        self::assertTrue(class_exists(\App\Addons\SeoContentAi\Filament\Pages\AutomationFlowsPage::class));

        $rule = (string) file_get_contents($base.'/Filament/Resources/AutomationRuleResource.php');
        $execution = (string) file_get_contents($base.'/Filament/Resources/AutomationExecutionResource.php');
        $ops = (string) file_get_contents($base.'/Filament/Pages/AutomationOperationsDashboard.php');
        $flows = (string) file_get_contents($base.'/Filament/Pages/AutomationFlowsPage.php');

        self::assertStringContainsString('BelongsToAdminAutomationPanel', $rule);
        self::assertStringContainsString('BelongsToAdminAutomationPanel', $execution);
        self::assertStringContainsString('BelongsToAdminAutomationPanel', $ops);
        self::assertStringContainsString('BelongsToAdminAutomationPanel', $flows);
        self::assertStringContainsString("slug = 'automation-rules'", $rule);
        self::assertStringContainsString("slug = 'automation-executions'", $execution);
        self::assertStringContainsString("slug = 'automation/operations'", $ops);
        self::assertStringContainsString("slug = 'automation/flows'", $flows);
        self::assertStringNotContainsString('extends SeoPanelResource', $rule);
        self::assertStringNotContainsString('extends SeoPanelPage', $ops);

        $trait = (string) file_get_contents($base.'/Filament/Concerns/BelongsToAdminAutomationPanel.php');
        self::assertStringContainsString("'Automation'", $trait);
        self::assertStringContainsString("=== 'admin'", $trait);
        self::assertStringContainsString('getNavigationGroup', $trait);
    }

    public function test_panel_provider_has_no_queue_worker_banner_hook(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Providers/SeoPanelProvider.php'
        );

        self::assertStringNotContainsString('SeoQueueManager', $source);
        self::assertStringNotContainsString('SeoQueueControlService', $source);
        self::assertStringNotContainsString('global-queue-worker-alert', $source);
        self::assertStringNotContainsString('shouldShowOfflineAlert', $source);
        self::assertStringNotContainsString('CONTENT_START', $source);
    }

    public function test_user_facing_queue_manager_strings_removed_from_locales(): void
    {
        $base = dirname(__DIR__, 2).'/lang';

        foreach (['en/filament.php', 'vi/filament.php'] as $relative) {
            $source = (string) file_get_contents($base.'/'.$relative);

            self::assertStringNotContainsString("'queue_manager'", $source, $relative);
            self::assertStringNotContainsString("'global_queue_alert'", $source, $relative);
            self::assertStringNotContainsString('Queue manager', $source, $relative);
            self::assertStringNotContainsString('Open Queue manager', $source, $relative);
            self::assertStringNotContainsString('Queue worker is offline', $source, $relative);
            self::assertStringNotContainsString('Pause audit queue', $source, $relative);
            self::assertStringNotContainsString('Stop pending audits', $source, $relative);
            self::assertStringNotContainsString('queue:work', $source, $relative);
        }
    }

    public function test_audit_and_link_map_no_longer_depend_on_queue_pause_controls(): void
    {
        $base = dirname(__DIR__, 2);

        $job = (string) file_get_contents($base.'/Jobs/AuditLinkStatusJob.php');
        $service = (string) file_get_contents($base.'/Services/LinkMapStatusAuditService.php');

        self::assertStringNotContainsString('SeoQueueControlService', $job);
        self::assertStringNotContainsString('isPausedForSite', $job);
        self::assertStringNotContainsString('SeoQueueControlService', $service);
        self::assertStringNotContainsString('isPausedForSite', $service);
        self::assertStringContainsString('AuditLinkStatusJob::dispatch', $service);
    }

    public function test_automation_operations_dashboard_keeps_execution_ops(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Pages/AutomationOperationsDashboard.php'
        );

        self::assertStringContainsString('recoverStale', $source);
        self::assertStringContainsString('retry', strtolower($source));
        self::assertStringNotContainsString('queue:work', $source);
        self::assertStringNotContainsString('SeoQueueControlService', $source);
        self::assertStringNotContainsString('worker_status', $source);
        self::assertStringNotContainsString('pending_work_total', $source);
    }
}
