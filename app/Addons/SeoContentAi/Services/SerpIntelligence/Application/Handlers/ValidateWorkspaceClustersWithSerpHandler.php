<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordCluster;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\SerpIntelligence\SerpClusterValidationService;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ValidateWorkspaceClustersWithSerpCommand;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use InvalidArgumentException;

final class ValidateWorkspaceClustersWithSerpHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpClusterValidationService $validator,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ValidateWorkspaceClustersWithSerpCommand) {
            throw new InvalidArgumentException('Expected ValidateWorkspaceClustersWithSerpCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $clusterRefs = $command->clusterRefs;
            if ($clusterRefs === null || $clusterRefs === []) {
                $clusterRefs = SeoKeywordCluster::query()
                    ->where('workspace_id', $workspace->id)
                    ->limit(50)
                    ->pluck('public_ref')
                    ->all();
            }

            $results = [];
            foreach ($clusterRefs as $clusterRef) {
                $clusterId = KeywordIntelligencePublicRef::resolveClusterIdStrict((string) $clusterRef);
                $cluster = SeoKeywordCluster::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('id', $clusterId)
                    ->first();

                if (! $cluster instanceof SeoKeywordCluster) {
                    $results[] = ['cluster_ref' => (string) $clusterRef, 'success' => false];

                    continue;
                }

                $results[] = [
                    'cluster_ref' => $cluster->public_ref,
                    'success' => true,
                    'suggestions' => $this->validator->suggest([]),
                ];
            }

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::WORKSPACE_CLUSTERS_VALIDATED,
                'Workspace cluster SERP validation complete.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'results' => $results,
                ],
            );
        });
    }
}
