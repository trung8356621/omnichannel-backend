<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Contracts;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentModelRoutingContext;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentModelSelection;

interface AgentModelRouter
{
    public function resolve(AgentModelRoutingContext $context): AgentModelSelection;
}
