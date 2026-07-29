<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationUpdateRequest
{
    public function __construct(
        public string $automationHashId,
        public AgentAutomationDefinitionRequest $definition,
    ) {}
}
