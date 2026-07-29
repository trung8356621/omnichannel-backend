<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Contracts;

use App\Addons\SeoContentAi\Models\AgentWorkspace\SeoAgentKnowledgeItem;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeChunkData;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeSearchResult;

interface AgentKnowledgeIndex
{
    /**
     * @param  list<AgentKnowledgeChunkData>  $chunks
     */
    public function indexItem(SeoAgentKnowledgeItem $item, array $chunks): void;

    public function removeItem(SeoAgentKnowledgeItem $item): void;

    public function search(AgentKnowledgeQuery $query): AgentKnowledgeSearchResult;

    public function adapterName(): string;
}
