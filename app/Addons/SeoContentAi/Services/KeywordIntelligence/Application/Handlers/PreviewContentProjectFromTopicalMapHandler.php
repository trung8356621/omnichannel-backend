<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordTopicalMapMutationService;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordTopicalMapToContentProjectConverter;
use InvalidArgumentException;

final class PreviewContentProjectFromTopicalMapHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordTopicalMapMutationService $mutations,
        private readonly KeywordTopicalMapToContentProjectConverter $converter,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof PreviewContentProjectFromTopicalMapCommand) {
            throw new InvalidArgumentException('Expected PreviewContentProjectFromTopicalMapCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $mapVersion = $this->mutations->resolveMapVersion($workspace, $command->mapVersionRef);
            $preview = $this->converter->preview(
                $workspace,
                $mapVersion,
                $command->policy,
                $command->clusterRefs,
            );

            $fingerprint = $this->buildFingerprint('keyword_intelligence.create_content_project', (int) $workspace->id, [
                'map_version_ref' => $command->mapVersionRef,
                'policy' => $command->policy,
                'cluster_refs' => $command->clusterRefs,
                'project_attributes' => $command->projectAttributes,
            ]);
            $token = null;
            if ($preview['requires_confirmation'] ?? false) {
                $token = $this->previewToken->issue($fingerprint);
            }

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::CONVERSION_PREVIEWED,
                'Topical map conversion preview ready.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'preview' => $preview,
                    'confirmation_token' => $token,
                ],
            );
        });
    }
}
