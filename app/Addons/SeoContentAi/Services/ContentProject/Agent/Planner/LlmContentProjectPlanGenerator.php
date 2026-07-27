<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Agent\Planner;

use App\Addons\SeoContentAi\Models\ContentProjectAutomationPolicy;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\AgentExecutionContext;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\Planner\Dtos\AgentPlanDraft;
use RuntimeException;

/**
 * LLM plan generator stub — not configured in Phase 7.
 */
final class LlmContentProjectPlanGenerator implements ContentProjectPlanGenerator
{
    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>|null  $projectContext
     */
    public function generate(
        AgentExecutionContext $context,
        string $objective,
        array $constraints = [],
        ?array $projectContext = null,
        ?ContentProjectAutomationPolicy $policy = null,
    ): AgentPlanDraft {
        throw new RuntimeException('LLM generator not configured');
    }
}
