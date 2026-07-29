<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Contracts;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Data\AgentGroundedContextPackage;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;

interface AgentKnowledgeRetriever
{
    public function retrieve(AgentKnowledgeQuery $query): AgentGroundedContextPackage;
}
