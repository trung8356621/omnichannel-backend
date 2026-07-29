<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ContentProjectGenerationRequested;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use InvalidArgumentException;

final class RerunProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SeoProjectWorkflowRunService $workflowRunService,
        private readonly ContentProjectDomainEvents $domainEvents,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RerunProjectItemsCommand) {
            throw new InvalidArgumentException('Expected RerunProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null || $project->isArchive()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived — rerun blocked.',
                    $projectId,
                );
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
            }

            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Rerun requires explicit item selection.',
                    $projectId,
                );
            }

            $settings = ['task_ids' => $itemIds, 'rerun' => true];

            return $this->businessLock->withLock(
                $this->businessLock->projectGenerate($projectId),
                function () use ($project, $projectId, $command, $itemIds, $settings): ContentProjectActionResult {
                    $run = $this->workflowRunService->startRun($project, $command->mode, $settings);
                    $executionRef = ContentProjectPublicRef::execution((int) $run->getKey());
                    $this->domainEvents->dispatchAfterCommit(new ContentProjectGenerationRequested(
                        $projectId,
                        $executionRef,
                        $itemIds,
                    ));

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                        'Rerun started.',
                        $projectId,
                        $itemIds,
                        metadata: [
                            'execution_ref' => $executionRef,
                            'rerun' => true,
                        ],
                    );
                },
            );
        });
    }
}
