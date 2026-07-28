<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\RepairGscDateRangeCommand;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\GscIntelligence\GscSyncDateRangeService;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class RepairGscDateRangeHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly GscSyncDateRangeService $dateRangeService,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RepairGscDateRangeCommand) {
            throw new InvalidArgumentException('Expected RepairGscDateRangeCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $this->assertPropertyActive($property);

            $lastSynced = $property->last_complete_date?->format('Y-m-d');
            $ranges = $lastSynced !== null && $lastSynced !== ''
                ? $this->dateRangeService->buildIncrementalRanges($lastSynced, $command->dateTo)
                : $this->dateRangeService->buildFullRanges(
                    $command->dateFrom ?? (new \DateTimeImmutable('today'))->modify('-90 days')->format('Y-m-d'),
                    $command->dateTo,
                );

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::DATE_RANGE_REPAIRED,
                'GSC date ranges computed for repair.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'ranges' => $ranges,
                    'range_count' => count($ranges),
                ],
            );
        });
    }
}
