<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\SaveTopicalMapVersionCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordTopicalMapMutationService;
use InvalidArgumentException;

final class SaveTopicalMapVersionHandler extends AbstractKeywordIntelligenceHandler
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
        if (! $command instanceof SaveTopicalMapVersionCommand) {
            throw new InvalidArgumentException('Expected SaveTopicalMapVersionCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $version = $this->mutations->saveMapVersion($workspace, $actor->actorId, $command->mode);

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::TOPICAL_MAP_VERSION_SAVED,
                'Topical map version saved.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'map_version_ref' => $version->public_ref,
                    'version' => $version->version,
                    'status' => $version->status,
                    'summary' => $version->summary,
                ],
            );
        });
    }
}
