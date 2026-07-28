<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordCluster;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\ApproveSerpClusterEvidenceCommand;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\SerpIntelligence\SerpEvidenceApplyService;
use InvalidArgumentException;

final class ApproveSerpClusterEvidenceHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpEvidenceApplyService $evidenceApply,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ApproveSerpClusterEvidenceCommand) {
            throw new InvalidArgumentException('Expected ApproveSerpClusterEvidenceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $evidence = $this->evidenceApply->resolveEvidence($command->evidenceRef);
            if ((int) ($evidence->workspace_id ?? 0) !== (int) $workspace->id) {
                throw new InvalidArgumentException('Evidence does not belong to workspace.');
            }

            $approved = $this->evidenceApply->approve($evidence, $actor->actorId);

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::EVIDENCE_APPROVED,
                'Cluster evidence approved.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'evidence_ref' => $approved->public_ref,
                    'cluster_ref' => KeywordIntelligencePublicRef::cluster((int) $approved->cluster_id),
                ],
            );
        });
    }
}
