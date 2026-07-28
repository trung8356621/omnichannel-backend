<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\MoveClusterPrimaryTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordTopicalMapMutationService;
use InvalidArgumentException;

final class MoveClusterPrimaryTopicHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordTopicalMapMutationService $mutations,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof MoveClusterPrimaryTopicCommand) {
            throw new InvalidArgumentException('Expected MoveClusterPrimaryTopicCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $link = $this->mutations->moveClusterPrimary($workspace, $command->clusterRef, $command->newTopicRef);

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::CLUSTER_PRIMARY_MOVED,
                'Cluster primary topic moved.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'cluster_ref' => $command->clusterRef,
                    'topic_ref' => $command->newTopicRef,
                    'link_ref' => $link->public_ref,
                ],
            );
        });
    }
}
