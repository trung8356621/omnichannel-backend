<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordWorkspace;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\AnalyzeKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordWorkspaceAnalysisService;
use InvalidArgumentException;
use Throwable;

final class AnalyzeKeywordWorkspaceHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordWorkspaceAnalysisService $analysis,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AnalyzeKeywordWorkspaceCommand) {
            throw new InvalidArgumentException('Expected AnalyzeKeywordWorkspaceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            return $this->runAnalysis($workspace, $command);
        });
    }

    private function runAnalysis(SeoKeywordWorkspace $workspace, AnalyzeKeywordWorkspaceCommand $command): ContentProjectActionResult
    {
        try {
            $result = $this->analysis->analyze($workspace, $command->clusteringStrategy);
        } catch (Throwable $e) {
            return ContentProjectActionResult::fail(
                KeywordIntelligenceActionCodes::FAILED,
                'Analysis failed: '.$e->getMessage(),
                metadata: ['workspace_ref' => $workspace->public_ref],
            );
        }

        return ContentProjectActionResult::ok(
            KeywordIntelligenceActionCodes::ANALYZED,
            'Workspace analysis completed.',
            metadata: [
                'workspace_ref' => $workspace->public_ref,
                'operation_ref' => $result['operation_ref'],
                'summary' => $result['summary'],
            ],
        );
    }
}
