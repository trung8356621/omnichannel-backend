<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectAutoScheduleService;
use InvalidArgumentException;

final class AutoScheduleProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectAutoScheduleService $autoScheduleService,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AutoScheduleProjectItemsCommand) {
            throw new InvalidArgumentException('Expected AutoScheduleProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
            }

            $resolvedItemIds = $this->resolveAutoScheduleItemIds($project, $itemIds);
            $fingerprint = $this->buildFingerprint($command->name(), $projectId, [
                'item_ids' => $resolvedItemIds,
                'options' => $command->options,
            ]);

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $resolvedItemIds,
                    $fingerprint,
                    [
                        'action' => 'auto_schedule',
                        'options' => $command->options,
                        'item_count' => count($resolvedItemIds),
                    ],
                    requiresConfirmation: false,
                );
            }

            return $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($project, $projectId, $itemIds, $command, $resolvedItemIds): ContentProjectActionResult {
                    $result = $this->autoScheduleService->schedule($project, $itemIds, $command->options);

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_SCHEDULED,
                        "{$result['scheduled']} item(s) auto-scheduled.",
                        $projectId,
                        $resolvedItemIds,
                        metadata: [
                            'affected_count' => (int) $result['scheduled'],
                            'slots' => $result['slots'],
                        ],
                    );
                },
            );
        });
    }

    /**
     * @param  list<int>  $itemIds
     * @return list<int>
     */
    private function resolveAutoScheduleItemIds(SeoProject $project, array $itemIds): array
    {
        if ($itemIds !== []) {
            return $itemIds;
        }

        return SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->active()
            ->where('article_id', '>', 0)
            ->where(function ($q): void {
                $q->whereNull('publish_queue_status')
                    ->orWhereIn('publish_queue_status', ['none', 'failed', 'cancelled', 'skipped']);
            })
            ->whereNull('scheduled_publish_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
