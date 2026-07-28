<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Enums\Gsc\GscMappingStatus;
use App\Addons\SeoContentAi\Enums\Gsc\GscQueryMappingType;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UnmapGscQueryCommand;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class UnmapGscQueryHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof UnmapGscQueryCommand) {
            throw new InvalidArgumentException('Expected UnmapGscQueryCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $mapping = $this->resolveQueryMapping($command->mappingRef, $property);

            $mapping->keyword_id = null;
            $mapping->cluster_id = null;
            $mapping->topic_id = null;
            $mapping->mapping_type = GscQueryMappingType::Unmapped;
            $mapping->status = GscMappingStatus::Stale;
            $mapping->metadata = array_merge((array) ($mapping->metadata ?? []), ['manual' => false, 'unmapped_at' => date('c')]);
            $mapping->save();

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::QUERY_UNMAPPED,
                'GSC query unmapped.',
                metadata: ['mapping_ref' => $mapping->public_ref],
            );
        });
    }
}
