<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Dtos;

use App\Addons\SeoContentAi\Models\AgentWorkspace\SeoAgentConversation;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;

/**
 * @param  array<string, mixed>  $formInput
 */
final readonly class AgentExecutionRequest
{
    /**
     * @param  array<string, mixed>  $formInput
     */
    public function __construct(
        public AgentWorkspaceContext $context,
        public SeoAgentConversation $conversation,
        public string $skillKey,
        public array $formInput = [],
        public string $mode = 'execute',
        public ?string $parentExecutionRef = null,
        public ?string $planRef = null,
        public ?int $stepIndex = null,
        public ?int $attempt = null,
    ) {}
}
