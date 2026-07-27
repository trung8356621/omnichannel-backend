<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Agent;

use App\Addons\SeoContentAi\Services\ContentProject\Agent\AgentExecutionContext;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceReadService as CoreKeywordIntelligenceReadService;

/**
 * Agent Gateway adapter — chuyển `AgentExecutionContext` + `input[]` thành
 * lời gọi `Application\KeywordIntelligenceReadService` (site_id, workspace_ref).
 * Mirror `ContentProjectAgentReadService`.
 */
final class KeywordIntelligenceReadService
{
    public function __construct(
        private readonly CoreKeywordIntelligenceReadService $reads,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listWorkspaces(AgentExecutionContext $context, array $input = []): array
    {
        return $this->reads->listWorkspaces($this->siteId($context), $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function getWorkspace(AgentExecutionContext $context, array $input): array
    {
        return ['workspace' => $this->reads->getWorkspace($this->siteId($context), $this->workspaceRef($input))];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listKeywords(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listKeywords($this->siteId($context), $this->workspaceRef($input), $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listClusters(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listClusters($this->siteId($context), $this->workspaceRef($input), $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function getTopicalMap(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getTopicalMap($this->siteId($context), $this->workspaceRef($input));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function getCannibalization(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listCannibalization($this->siteId($context), $this->workspaceRef($input));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function getAnalysisOperation(AgentExecutionContext $context, array $input): array
    {
        $ref = trim((string) ($input['operation_ref'] ?? ''));

        return ['operation' => $this->reads->getAnalysisOperation($this->siteId($context), $ref)];
    }

    private function siteId(AgentExecutionContext $context): int
    {
        return (int) ($context->resolvedSiteId ?? 0);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function workspaceRef(array $input): string
    {
        return trim((string) ($input['workspace_ref'] ?? ''));
    }
}
