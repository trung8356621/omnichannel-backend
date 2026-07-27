<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AddContentProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AddContentProjectItemsCommand) {
            throw new InvalidArgumentException('Expected AddContentProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Cannot add items to archived project.',
                    $projectId,
                );
            }

            if ($command->items === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Item list is empty.',
                    $projectId,
                );
            }

            $createdIds = DB::connection('omi_seo_ai')->transaction(function () use ($project, $command): array {
                $ids = [];
                foreach ($command->items as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $task = SeoProjectTask::query()->create([
                        'project_id' => (int) $project->getKey(),
                        'site_id' => (int) ($project->site_id ?? 0),
                        'type' => (string) ($row['type'] ?? SeoProjectTask::TYPE_CREATE),
                        'post_type' => (string) ($row['post_type'] ?? SeoProjectTask::POST_TYPE_ARTICLE),
                        'keyword' => (string) ($row['keyword'] ?? $row['title'] ?? ''),
                        'title' => (string) ($row['title'] ?? $row['keyword'] ?? ''),
                        'status' => SeoProjectTask::STATUS_PENDING,
                        'article_id' => isset($row['article_id']) ? (int) $row['article_id'] : null,
                        'target_date' => $row['target_date'] ?? now()->toDateString(),
                    ]);
                    $ids[] = (int) $task->getKey();
                }

                $project->update([
                    'total_tasks' => (int) $project->tasks()->count(),
                ]);

                return $ids;
            });

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_ADDED,
                count($createdIds).' item(s) added.',
                $projectId,
                $createdIds,
                metadata: ['affected_count' => count($createdIds)],
            );
        });
    }
}
