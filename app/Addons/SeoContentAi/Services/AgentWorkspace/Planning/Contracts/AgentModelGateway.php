<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Contracts;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentConversationSummary;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentModelSelection;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentSummarizationRequest;

interface AgentModelGateway
{
    /**
     * @param  array<string, mixed>  $assembledContext
     * @return array{response: AgentPlanningResponse, meta: array<string, mixed>}
     */
    public function plan(
        AgentPlanningRequest $request,
        AgentModelSelection $model,
        array $assembledContext,
    ): array;

    /**
     * @return array{summary: AgentConversationSummary, meta: array<string, mixed>}
     */
    public function summarize(
        AgentSummarizationRequest $request,
        AgentModelSelection $model,
    ): array;
}
