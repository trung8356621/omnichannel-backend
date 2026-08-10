<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\SyncContentProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\SeoProjectTaskSyncService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SyncContentProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SeoProjectTaskSyncService $taskSync,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof SyncContentProjectItemsCommand) {
            throw new InvalidArgumentException('Expected SyncContentProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Cannot sync items on archived project.',
                    $projectId,
                );
            }

            $lockKey = $this->businessLock->projectSchedule($projectId);

            return $this->businessLock->withLock($lockKey, function () use ($project, $projectId, $command): ContentProjectActionResult {
                $syncResult = DB::connection('omi_seo_ai')->transaction(
                    fn () => $this->taskSync->syncWithResult($project->fresh() ?? $project, $command->tasksData),
                );

                $affectedIds = array_values(array_unique(array_merge(
                    $syncResult->createdTaskIds,
                    $syncResult->updatedTaskIds,
                    $syncResult->reactivatedTaskIds,
                )));

                return ContentProjectActionResult::ok(
                    ContentProjectActionCodes::ITEMS_SYNCED,
                    'Items synced.',
                    $projectId,
                    $affectedIds,
                    $syncResult->warnings,
                    metadata: [
                        'affected_count' => count($affectedIds),
                        'created_count' => count($syncResult->createdTaskIds),
                        'updated_count' => count($syncResult->updatedTaskIds),
                        'cancelled_count' => count($syncResult->cancelledTaskIds),
                    ],
                );
            });
        });
    }
}
