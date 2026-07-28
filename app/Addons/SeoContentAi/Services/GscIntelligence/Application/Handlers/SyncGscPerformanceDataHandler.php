<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Enums\Gsc\GscSyncRunStatus;
use App\Addons\SeoContentAi\Enums\Gsc\GscSyncStage;
use App\Addons\SeoContentAi\Models\SeoGscSyncRun;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\SyncGscPerformanceDataCommand;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\GscIntelligence\Data\GscSearchAnalyticsRequest;
use App\Addons\SeoContentAi\Services\GscIntelligence\GscSuggestedMappingPersistService;
use App\Addons\SeoContentAi\Services\GscIntelligence\GscSyncDateRangeService;
use App\Addons\SeoContentAi\Services\GscIntelligence\GscSyncOperationService;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class SyncGscPerformanceDataHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly GscSyncOperationService $syncOperation,
        private readonly GscSyncDateRangeService $dateRangeService,
        private readonly GscSuggestedMappingPersistService $suggestedMappingPersist,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof SyncGscPerformanceDataCommand) {
            throw new InvalidArgumentException('Expected SyncGscPerformanceDataCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $this->assertPropertyActive($property);

            $endDate = $command->dateTo ?? $this->dateRangeService->latestAvailableEnd()['end'];
            $startDate = $command->dateFrom
                ?? ($property->last_complete_date?->format('Y-m-d')
                    ?? (new \DateTimeImmutable($endDate))->modify('-27 days')->format('Y-m-d'));

            $providerKey = trim((string) ($command->providerKey ?? $property->provider_key ?? 'manual_import'));

            $syncRun = new SeoGscSyncRun([
                'public_ref' => 'pending',
                'tenant_id' => $property->tenant_id,
                'site_id' => $property->site_id,
                'property_id' => $property->id,
                'provider_key' => $providerKey,
                'date_from' => $startDate,
                'date_to' => $endDate,
                'search_type' => $property->default_search_type,
                'status' => GscSyncRunStatus::Processing,
                'started_at' => now(),
                'created_by' => $actor->actorId,
            ]);
            $syncRun->save();
            $syncRun->public_ref = KeywordIntelligencePublicRef::gscSyncRun((int) $syncRun->id);
            $syncRun->save();

            $request = new GscSearchAnalyticsRequest(
                tenantRef: null,
                siteRef: (string) $property->site_id,
                propertyRef: $property->public_ref,
                startDate: $startDate,
                endDate: $endDate,
                providerKey: $providerKey,
                options: $command->options,
            );

            $result = $this->syncOperation->sync($request, [
                'site_id' => (string) $property->site_id,
                'site_id_int' => (int) $property->site_id,
                'property_id' => (int) $property->id,
                'tenant_id' => $property->tenant_id,
                'search_type' => (string) ($property->default_search_type?->value ?? 'web'),
                'source' => $providerKey,
                'provider_context' => $command->options,
            ]);

            $syncRun->operation_ref = (string) ($result['operation_ref'] ?? null);
            $syncRun->received_rows = (int) ($result['row_count'] ?? 0);
            $syncRun->persisted_rows = (int) ($result['persisted']['inserted'] ?? 0) + (int) ($result['persisted']['updated'] ?? 0);
            $syncRun->completed_at = now();

            if (($result['success'] ?? false) !== true) {
                $syncRun->status = GscSyncRunStatus::Failed;
                $syncRun->error_code = (string) ($result['error_code'] ?? 'gsc.sync_failed');
                $syncRun->error_message = (string) ($result['error_message'] ?? '');
                $syncRun->save();

                return ContentProjectActionResult::fail(
                    (string) ($result['error_code'] ?? GscIntelligenceActionCodes::FAILED),
                    (string) ($result['error_message'] ?? 'GSC sync failed.'),
                    metadata: ['property_ref' => $property->public_ref, 'sync_run_ref' => $syncRun->public_ref],
                );
            }

            $mappingStats = $this->suggestedMappingPersist->persistFromSyncResult(
                $property,
                is_array($result['mappings'] ?? null) ? $result['mappings'] : [],
            );

            $partial = ($result['stage'] ?? '') === GscSyncStage::PartiallyCompleted->value || ($result['partial'] ?? false) === true;
            $syncRun->status = $partial ? GscSyncRunStatus::PartiallyCompleted : GscSyncRunStatus::Completed;
            $syncRun->save();

            $property->last_synced_at = now();
            $property->last_complete_date = $endDate;
            $property->save();

            return ContentProjectActionResult::ok(
                $partial ? GscIntelligenceActionCodes::SYNC_PARTIAL : GscIntelligenceActionCodes::SYNC_STARTED,
                'GSC sync finished.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'sync_run_ref' => $syncRun->public_ref,
                    'operation_ref' => $result['operation_ref'] ?? null,
                    'mapping_persist' => $mappingStats,
                    'result' => $result,
                ],
            );
        });
    }
}
