<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Contracts;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data\AgentAutomationNotificationData;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;

interface AgentAutomationNotificationService
{
    /**
     * @param  array<string, mixed>  $notificationConfig
     * @param  array<string, mixed>  $runContext
     * @return array{sent: bool, delayed: bool, skipped: bool, reason?: string, data?: array<string, mixed>}
     */
    public function maybeNotify(
        AgentWorkspaceContext $context,
        array $notificationConfig,
        AgentAutomationNotificationData $data,
        array $runContext,
    ): array;
}
