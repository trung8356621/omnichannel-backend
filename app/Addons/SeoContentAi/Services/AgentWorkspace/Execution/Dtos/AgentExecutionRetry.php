<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Dtos;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;

final readonly class AgentExecutionRetry
{
    public function __construct(
        public AgentWorkspaceContext $context,
        public string $executionRef,
    ) {}
}
