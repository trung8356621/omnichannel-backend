<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Contracts;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Data\AgentGroundedContextPackage;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;

interface AgentGroundingContextProvider
{
    public function build(
        AgentPlanningRequest $request,
        ?AgentWorkspaceContext $context = null,
    ): AgentGroundedContextPackage;
}
