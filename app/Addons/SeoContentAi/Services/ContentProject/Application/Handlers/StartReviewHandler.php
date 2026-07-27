<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\StartReviewCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ContentProjectReviewRequested;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use InvalidArgumentException;

final class StartReviewHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectDomainEvents $domainEvents,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof StartReviewCommand) {
            throw new InvalidArgumentException('Expected StartReviewCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived.',
                    $projectId,
                );
            }

            $query = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->active()
                ->whereIn('status', [SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::STATUS_PENDING]);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
                $query->whereIn('id', $itemIds);
            }

            $affectedIds = $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if ($affectedIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'No items eligible for review.',
                    $projectId,
                );
            }

            SeoProjectTask::query()
                ->whereIn('id', $affectedIds)
                ->update(['status' => SeoProjectTask::STATUS_REVIEWING]);

            $this->domainEvents->dispatchAfterCommit(new ContentProjectReviewRequested($projectId, $affectedIds));

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_REVIEW_STARTED,
                count($affectedIds).' item(s) moved to review.',
                $projectId,
                $affectedIds,
                metadata: ['affected_count' => count($affectedIds)],
            );
        });
    }
}
