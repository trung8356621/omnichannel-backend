<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationRunRequest
{
    public function __construct(
        public string $automationHashId,
        public string $triggerSource = 'manual',
        public ?string $scheduledAtUtc = null,
        public bool $force = false,
    ) {}
}
