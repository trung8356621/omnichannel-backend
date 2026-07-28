<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Enums\Gsc\GscSyncRunStatus;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\CancelGscSyncCommand;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class CancelGscSyncHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CancelGscSyncCommand) {
            throw new InvalidArgumentException('Expected CancelGscSyncCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $syncRun = $this->resolveSyncRun($command->syncRunRef, $property);

            if (! in_array($syncRun->status, [GscSyncRunStatus::Accepted, GscSyncRunStatus::Processing], true)) {
                return ContentProjectActionResult::fail(
                    GscIntelligenceActionCodes::VALIDATION_FAILED,
                    'Sync run cannot be cancelled.',
                );
            }

            $syncRun->status = GscSyncRunStatus::Cancelled;
            $syncRun->completed_at = now();
            $syncRun->result_code = 'cancelled';
            $syncRun->save();

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::SYNC_CANCELLED,
                'GSC sync cancelled.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'sync_run_ref' => $syncRun->public_ref,
                ],
            );
        });
    }
}
