<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CancelTopicalMapBuildCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordTopicalMapBuildLock;
use InvalidArgumentException;

final class CancelTopicalMapBuildHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        \App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard $tenantGuard,
        \App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken $previewToken,
        private readonly KeywordTopicalMapBuildLock $buildLock,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CancelTopicalMapBuildCommand) {
            throw new InvalidArgumentException('Expected CancelTopicalMapBuildCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            if (! $this->buildLock->isHeld($workspace->public_ref)) {
                return ContentProjectActionResult::ok(
                    KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_CANCELLED,
                    'No topical map build in progress.',
                    metadata: ['workspace_ref' => $workspace->public_ref, 'was_building' => false],
                );
            }

            $this->buildLock->requestCancel($workspace->public_ref);

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_CANCELLED,
                'Topical map build cancellation requested.',
                metadata: ['workspace_ref' => $workspace->public_ref, 'was_building' => true],
            );
        });
    }
}
