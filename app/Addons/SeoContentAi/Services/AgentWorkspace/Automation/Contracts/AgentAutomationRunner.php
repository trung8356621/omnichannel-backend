<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Contracts;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data\AgentAutomationRunResult;

interface AgentAutomationRunner
{
    public function run(int $runId): AgentAutomationRunResult;
}
