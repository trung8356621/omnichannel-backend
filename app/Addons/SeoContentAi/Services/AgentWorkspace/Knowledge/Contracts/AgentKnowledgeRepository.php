<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Contracts;

use App\Addons\SeoContentAi\Models\AgentWorkspace\SeoAgentKnowledgeItem;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeItemData;

interface AgentKnowledgeRepository
{
    /**
     * @param  array<string, mixed>  $attrs
     */
    public function create(array $attrs): SeoAgentKnowledgeItem;

    public function findByHash(string $hashId, AgentWorkspaceContext $context): ?SeoAgentKnowledgeItem;

    public function findDuplicate(int $siteId, string $contentHash, string $scopeType, ?string $scopeRef): ?SeoAgentKnowledgeItem;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<SeoAgentKnowledgeItem>
     */
    public function listForContext(AgentWorkspaceContext $context, array $filters = []): array;

    public function toData(SeoAgentKnowledgeItem $item): AgentKnowledgeItemData;

    public function save(SeoAgentKnowledgeItem $item): void;
}
