<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RecoverStuckPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use InvalidArgumentException;

/**
 * Recover stuck Publishing — không WordPress, không normal Cancel transition.
 */
final class RecoverStuckPublishingHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectPublishingQueueService $queueService,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RecoverStuckPublishingCommand) {
            throw new InvalidArgumentException('Expected RecoverStuckPublishingCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            if ($actor->actorType === 'user' && ! SeoAccessControl::canManageContentProjectWorkflow()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    'Không có quyền recover stuck publishing.',
                );
            }

            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Item list is empty.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $itemIds,
                    $this->buildFingerprint($command->name(), $projectId, [
                        'item_ids' => $itemIds,
                        'target' => $command->target,
                    ]),
                    [
                        'action' => 'recover_stuck_publishing',
                        'target' => $command->target,
                        'item_count' => count($itemIds),
                    ],
                    requiresConfirmation: false,
                );
            }

            return $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($project, $projectId, $itemIds, $command): ContentProjectActionResult {
                    $affected = $this->queueService->recoverStuckPublishing(
                        $project,
                        $itemIds,
                        $command->target,
                        $command->rescheduleAt,
                    );

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_PUBLISH_RECOVERED,
                        "{$affected} item(s) recovered from stuck publishing.",
                        $projectId,
                        $itemIds,
                        metadata: [
                            'affected_count' => $affected,
                            'target' => $command->target,
                            'wordpress_called' => false,
                        ],
                    );
                },
            );
        });
    }
}
