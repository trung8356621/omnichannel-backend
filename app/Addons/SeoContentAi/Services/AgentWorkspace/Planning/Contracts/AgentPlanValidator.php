<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Contracts;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentPlanningValidationResult;

interface AgentPlanValidator
{
    public function validate(
        AgentPlanningResponse $response,
        AgentPlanningRequest $request,
        AgentWorkspaceContext $context,
    ): AgentPlanningValidationResult;
}
