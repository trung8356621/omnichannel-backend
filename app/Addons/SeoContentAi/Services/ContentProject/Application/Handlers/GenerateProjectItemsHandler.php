<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
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
use App\Addons\SeoContentAi\Extension\Resolvers\PipelineResolver;
use InvalidArgumentException;
use RuntimeException;

final class GenerateProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SeoProjectWorkflowRunService $workflowRunService,
        private readonly ContentProjectDomainEvents $domainEvents,
        private readonly PipelineResolver $pipelineResolver,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof GenerateProjectItemsCommand) {
            throw new InvalidArgumentException('Expected GenerateProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null || $project->isArchive()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived — generate blocked.',
                    $projectId,
                );
            }

            try {
                $pipeline = $this->pipelineResolver->resolve('article');
                $validation = $pipeline->validate([
                    'project_id' => $projectId,
                    'mode' => $command->mode,
                ]);
                if (! ($validation['ok'] ?? false)) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        implode(' ', $validation['errors'] ?? ['Pipeline validation failed.']),
                        $projectId,
                    );
                }
            } catch (RuntimeException $e) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    $e->getMessage(),
                    $projectId,
                );
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
            }

            $classifier = app(\App\Addons\SeoContentAi\Services\ContentProject\ContentProjectItemGenerationClassifier::class);
            $preview = $classifier->preview($project);

            if ($itemIds === []) {
                if ($preview->runCount() <= 0) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        'No truly pending items to generate.',
                        $projectId,
                        metadata: ['preview' => $preview->toArray()],
                    );
                }
                $itemIds = $preview->runnableTaskIds();
            } else {
                $allowed = array_flip($preview->runnableTaskIds());
                $itemIds = array_values(array_filter(
                    $itemIds,
                    static fn (int $id): bool => isset($allowed[$id]),
                ));
                if ($itemIds === []) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        'Selected items are not eligible for generate-pending (already generated or blocked).',
                        $projectId,
                        metadata: ['preview' => $preview->toArray()],
                    );
                }
            }

            if ($preview->failClosed && ! $command->technicalConfirmFullRerun) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Generate pending fail-closed: would select entire project despite historical execution.',
                    $projectId,
                    metadata: ['preview' => $preview->toArray()],
                );
            }

            $settings = [
                'task_ids' => $itemIds,
                'technical_confirm_full_rerun' => $command->technicalConfirmFullRerun,
            ];

            return $this->businessLock->withLock(
                $this->businessLock->projectGenerate($projectId),
                function () use ($project, $projectId, $command, $itemIds, $settings): ContentProjectActionResult {
                    $run = $this->workflowRunService->startRun($project, $command->mode, $settings);
                    $limit = $command->mode === SeoProjectRun::MODE_TEST
                        ? SeoProjectWorkflowRunService::TEST_RUN_LIMIT
                        : null;
                    $run = $this->workflowRunService->prepareRunQueue($project, $run, $limit);
                    $runId = (int) $run->getKey();
                    $executionRef = ContentProjectPublicRef::execution($runId);
                    $this->domainEvents->dispatchAfterCommit(new ContentProjectGenerationRequested(
                        $projectId,
                        $executionRef,
                        $itemIds,
                    ));

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                        'Generate pending started for '.count($itemIds).' item(s).',
                        $projectId,
                        $itemIds,
                        metadata: [
                            'execution_ref' => $executionRef,
                            'task_ids' => $itemIds,
                        ],
                    );
                },
            );
        });
    }
}
