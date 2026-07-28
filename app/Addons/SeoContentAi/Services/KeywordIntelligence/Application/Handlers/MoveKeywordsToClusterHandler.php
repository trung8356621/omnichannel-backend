<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\MoveKeywordsToClusterCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordClusterMutationService;
use InvalidArgumentException;

final class MoveKeywordsToClusterHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordClusterMutationService $mutations,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof MoveKeywordsToClusterCommand) {
            throw new InvalidArgumentException('Expected MoveKeywordsToClusterCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            if ($command->forceReviewedMismatch && in_array($actor->actorType, ['api', 'agent'], true)) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::FORBIDDEN,
                    'Agent/API cannot use force_reviewed_mismatch.',
                );
            }

            $keywordIds = array_map(
                static fn (string $ref): int => KeywordIntelligencePublicRef::resolveKeywordIdStrict($ref),
                $command->keywordRefs,
            );
            $destId = KeywordIntelligencePublicRef::resolveClusterIdStrict($command->destinationClusterRef);

            $moved = $this->mutations->moveKeywords(
                $workspace,
                $keywordIds,
                $destId,
                $command->forceReviewedMismatch,
            );

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::KEYWORDS_REVIEWED,
                'Keywords moved.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'moved' => $moved,
                    'destination_cluster_ref' => $command->destinationClusterRef,
                ],
            );
        });
    }
}
