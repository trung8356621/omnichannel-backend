<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoGscQueryMapping;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\AddGscQueriesToKeywordWorkspaceCommand;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordImportService;
use InvalidArgumentException;

final class AddGscQueriesToKeywordWorkspaceHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordImportService $keywordImport,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AddGscQueriesToKeywordWorkspaceCommand) {
            throw new InvalidArgumentException('Expected AddGscQueriesToKeywordWorkspaceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $mappings = $command->queryRefs === []
                ? SeoGscQueryMapping::query()->where('property_id', $property->id)->whereNull('keyword_id')->limit(200)->get()->all()
                : array_map(fn (string $ref): SeoGscQueryMapping => $this->resolveQueryMapping($ref, $property), $command->queryRefs);

            $rows = [];
            foreach ($mappings as $mapping) {
                $rows[] = ['keyword' => $mapping->sample_query ?? $mapping->normalized_query];
            }

            if ($rows === []) {
                return ContentProjectActionResult::fail(GscIntelligenceActionCodes::VALIDATION_FAILED, 'No GSC queries to add.');
            }

            $result = $this->keywordImport->import(
                $workspace,
                $rows,
                $command->keepDuplicates,
                'gsc_intelligence',
                $actor->actorId,
            );

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::QUERIES_ADDED,
                'GSC queries added to workspace.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'workspace_ref' => $workspace->public_ref,
                    'import' => $result,
                ],
            );
        });
    }
}
