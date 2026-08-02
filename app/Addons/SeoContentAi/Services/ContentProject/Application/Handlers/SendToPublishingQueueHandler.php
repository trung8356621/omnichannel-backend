<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectPublishedDefinition;
use App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueueHandoffEligibility;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\SendToPublishingQueueCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueService;
use InvalidArgumentException;

/**
 * Content Project → Publishing Queue handoff. No WordPress. No auto schedule.
 */
final class SendToPublishingQueueHandler extends AbstractPublishingHandler
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
        if (! $command instanceof SendToPublishingQueueCommand) {
            throw new InvalidArgumentException('Expected SendToPublishingQueueCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            if (! SeoAccessControl::canManageContentProjectWorkflow()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    'Content Manager cannot send items to Publishing Queue.',
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

            $tasks = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->whereIn('id', $itemIds)
                ->whereNull('archived_at')
                ->get();

            $eligible = [];
            $warnings = [];
            foreach ($tasks as $task) {
                $row = [
                    'article_id' => (int) ($task->article_id ?? 0),
                    'publishing_queued_at' => $task->publishing_queued_at?->toIso8601String(),
                    'generation_status' => (string) ($task->status ?? ''),
                    'execution_status' => '',
                    'generation_completed_at' => $task->completed_at?->toIso8601String(),
                    'content_manager_reviewed_at' => $task->content_manager_reviewed_at?->toIso8601String(),
                    'is_content_manager_reviewed' => $task->content_manager_reviewed_at !== null,
                    'lifecycle' => '',
                    'queue_status' => (string) ($task->publish_queue_status ?? 'none'),
                    'publish_published_at' => $task->publish_published_at?->toIso8601String(),
                    'is_genuinely_running' => in_array((string) $task->status, [
                        SeoProjectTask::STATUS_WRITING,
                        SeoProjectTask::STATUS_PROCESSING,
                    ], true),
                ];
                if (ContentProjectPublishedDefinition::matches($row)) {
                    continue;
                }
                if (! PublishingQueueHandoffEligibility::canSend($row)) {
                    continue;
                }
                $eligible[] = (int) $task->getKey();
                if (PublishingQueueHandoffEligibility::needsContentManagerWarning($row)) {
                    $warnings[] = 'item_'.$task->getKey().'_needs_review_unmarked';
                }
            }

            if ($eligible === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No eligible items to send to Publishing Queue.',
                    $projectId,
                );
            }

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $eligible,
                    $this->buildFingerprint($command->name(), $projectId, ['item_ids' => $eligible]),
                    ['action' => 'send_to_publishing_queue', 'items' => $eligible],
                    $warnings,
                );
            }

            return $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($project, $projectId, $eligible, $actor, $warnings): ContentProjectActionResult {
                    $affected = $this->queueService->acceptHandoff(
                        $project,
                        $eligible,
                        $actor->actorId !== null ? (int) $actor->actorId : null,
                    );

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_SENT_TO_PUBLISHING_QUEUE,
                        'Sent to Publishing Queue (Unscheduled).',
                        $projectId,
                        $eligible,
                        $warnings,
                        metadata: [
                            'affected_count' => $affected,
                            'publish_state' => 'unscheduled',
                            'wordpress_called' => false,
                            'scheduled' => false,
                        ],
                    );
                },
            );
        });
    }
}
