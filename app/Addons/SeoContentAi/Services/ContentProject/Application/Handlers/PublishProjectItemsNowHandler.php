<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueRunner;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueService;
use InvalidArgumentException;

final class PublishProjectItemsNowHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectPublishingQueueService $queueService,
        private readonly ContentProjectPublishingQueueRunner $queueRunner,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof PublishProjectItemsNowCommand) {
            throw new InvalidArgumentException('Expected PublishProjectItemsNowCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
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

            $fingerprint = $this->buildFingerprint($command->name(), $projectId, [
                'item_ids' => $itemIds,
            ]);

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $itemIds,
                    $fingerprint,
                    [
                        'action' => 'publish_now',
                        'items' => array_map(
                            static fn (int $id): array => ['item_id' => $id, 'publish_at' => now()->toIso8601String()],
                            $itemIds,
                        ),
                    ],
                );
            }

            $token = $command->confirmationToken ?? $actor->confirmationToken;
            $confirmationFailure = $this->assertConfirmationToken(
                $token,
                $fingerprint,
                required: $this->requiresConfirmation($actor, $token),
                projectId: $projectId,
            );
            if ($confirmationFailure instanceof ContentProjectActionResult) {
                return $confirmationFailure;
            }

            $affected = 0;
            foreach ($itemIds as $itemId) {
                $affected += (int) $this->businessLock->withLock(
                    $this->businessLock->itemPublish($itemId),
                    fn (): int => $this->queueService->publishNow($project, [$itemId]),
                );
            }

            $dispatchStats = $this->queueRunner->dispatchDue();
            $this->consumeConfirmationToken($command->confirmationToken ?? $actor->confirmationToken);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED,
                "{$affected} item(s) queued for immediate publish.",
                $projectId,
                $itemIds,
                metadata: [
                    'affected_count' => $affected,
                    'dispatch' => $dispatchStats,
                ],
            );
        });
    }
}
