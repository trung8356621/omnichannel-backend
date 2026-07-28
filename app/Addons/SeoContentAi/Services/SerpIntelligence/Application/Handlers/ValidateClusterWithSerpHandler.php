<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordCluster;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKiKeyword;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ValidateClusterWithSerpCommand;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\SerpIntelligence\SerpClusterValidationService;
use InvalidArgumentException;

final class ValidateClusterWithSerpHandler extends AbstractSerpIntelligenceHandler
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
        if (! $command instanceof ValidateClusterWithSerpCommand) {
            throw new InvalidArgumentException('Expected ValidateClusterWithSerpCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $clusterId = KeywordIntelligencePublicRef::resolveClusterIdStrict($command->clusterRef);
            $cluster = SeoKeywordCluster::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', $clusterId)
                ->first();

            if (! $cluster instanceof SeoKeywordCluster) {
                throw new InvalidArgumentException('Cluster not found.');
            }

            $members = SeoKiKeyword::query()
                ->where('cluster_id', $cluster->id)
                ->get()
                ->map(fn (SeoKiKeyword $k): array => [
                    'keyword_ref' => $k->public_ref,
                    'results' => [],
                ])
                ->all();

            $suggestions = $this->validator->suggest($members);

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::CLUSTER_VALIDATED,
                'Cluster SERP validation complete.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'cluster_ref' => $cluster->public_ref,
                    'suggestions' => $suggestions,
                ],
            );
        });
    }
}
