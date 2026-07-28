<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands\CancelSerpCollectionCommand;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\SerpIntelligence\SerpCollectionOperationService;
use InvalidArgumentException;

final class CancelSerpCollectionHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpCollectionOperationService $collection,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CancelSerpCollectionCommand) {
            throw new InvalidArgumentException('Expected CancelSerpCollectionCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            if (! $this->collection->cancel($command->operationRef)) {
                return ContentProjectActionResult::fail(
                    SerpIntelligenceActionCodes::NOT_FOUND,
                    'Collection operation not found or already finished.',
                );
            }

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::COLLECTION_CANCELLED,
                'Collection cancelled.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'operation_ref' => $command->operationRef,
                ],
            );
        });
    }
}
