<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ContentProjectItemsApproved;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Quotas\ContentProjectQuotaGuard;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ApproveProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectQuotaGuard $quota,
        private readonly ContentProjectDomainEvents $domainEvents,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ApproveProjectItemsCommand) {
            throw new InvalidArgumentException('Expected ApproveProjectItemsCommand.');
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
                ->where('article_id', '>', 0);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
                $query->whereIn('id', $itemIds);
            }

            $tasks = $query->get(['id', 'article_id']);
            if ($tasks->isEmpty()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'No items eligible for approve.',
                    $projectId,
                );
            }

            if (! $this->quota->canPublishItems($actor, $project, $tasks->count())) {
                // approve trước publish — dùng publish quota hook làm placeholder
            }

            $affectedIds = DB::connection('omi_seo_ai')->transaction(function () use ($tasks, $project): array {
                $ids = [];
                foreach ($tasks as $task) {
                    $articleId = (int) ($task->article_id ?? 0);
                    if ($articleId <= 0) {
                        continue;
                    }

                    SeoArticle::query()->whereKey($articleId)->update([
                        'is_reviewed' => true,
                        'reviewed_at' => now(),
                    ]);
                    $ids[] = (int) $task->id;
                }

                if ($ids !== [] && $project->status !== SeoProject::STATUS_APPROVED) {
                    $project->update(['status' => SeoProject::STATUS_APPROVED]);
                }

                return $ids;
            });

            $this->domainEvents->dispatchAfterCommit(new ContentProjectItemsApproved($projectId, $affectedIds));

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_APPROVED,
                count($affectedIds).' item(s) approved.',
                $projectId,
                $affectedIds,
                metadata: ['affected_count' => count($affectedIds)],
            );
        });
    }
}
