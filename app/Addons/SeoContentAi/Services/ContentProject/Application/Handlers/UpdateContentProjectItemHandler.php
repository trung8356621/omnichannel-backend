<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Enums\SeoProjectTaskStatus;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectTaskStatusNormalizer;
use InvalidArgumentException;
use RuntimeException;

final class UpdateContentProjectItemHandler extends AbstractPublishingHandler
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
        if (! $command instanceof UpdateContentProjectItemCommand) {
            throw new InvalidArgumentException('Expected UpdateContentProjectItemCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $itemId = ContentProjectPublicRef::resolveItemId($command->itemRef);
            $task = SeoProjectTask::query()->find($itemId);
            if (! $task instanceof SeoProjectTask) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'Item not found.',
                );
            }

            $project = SeoProject::query()->find((int) $task->project_id);
            if (! $project instanceof SeoProject) {
                throw new RuntimeException('Project không tồn tại.');
            }

            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Cannot update item on archived project.',
                    $projectId,
                );
            }

            $allowed = array_intersect_key($command->attributes, array_flip(['keyword', 'title', 'target_date', 'status']));
            if ($allowed === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No updatable attributes.',
                    $projectId,
                    affectedItemIds: [$itemId],
                );
            }

            if (array_key_exists('status', $allowed)) {
                try {
                    $normalized = ContentProjectTaskStatusNormalizer::normalizeOrFail(
                        is_string($allowed['status'] ?? null) ? (string) $allowed['status'] : null,
                    );
                } catch (InvalidArgumentException $e) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        $e->getMessage(),
                        $projectId,
                        affectedItemIds: [$itemId],
                    );
                }

                // Manual status writes limited to non-terminal workflow labels — archive/cancel via dedicated commands.
                if (in_array($normalized, [SeoProjectTaskStatus::Archived, SeoProjectTaskStatus::Cancelled], true)) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        'Use archive/cancel commands — cannot set status='.$normalized->value.' via item update.',
                        $projectId,
                        affectedItemIds: [$itemId],
                    );
                }

                $allowed['status'] = $normalized->value;
            }

            $task->update($allowed);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_UPDATED,
                'Item updated.',
                $projectId,
                [$itemId],
                metadata: ['updated' => array_keys($allowed)],
            );
        });
    }
}
