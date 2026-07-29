<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Contracts;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentProposedPlan;

interface AgentPlanningOrchestrator
{
    /**
     * @return array<string, mixed>
     */
    public function plan(AgentPlanningRequest $request): array;

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function answerClarification(AgentPlanningRequest $request, array $answers): array;

    public function validateProposal(AgentPlanningResponse $response, AgentPlanningRequest $request): AgentPlanningResponse;

    /**
     * @param  array<string, mixed>  $edits
     * @return array<string, mixed>
     */
    public function editPlan(AgentPlanningRequest $request, AgentProposedPlan $plan, array $edits): array;

    /**
     * @return array<string, mixed>
     */
    public function savePlan(AgentPlanningRequest $request, AgentProposedPlan $plan): array;

    /**
     * @param  array<string, mixed>  $resultContext
     * @return list<array{skill_key: string, name: string}>
     */
    public function suggestNextActions(AgentPlanningRequest $request, array $resultContext = []): array;
}
