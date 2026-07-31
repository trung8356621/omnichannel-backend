<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ContentProjectGenerationRequested;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectRerunEligibilityGuard;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectGenerationRecoveryService;
use App\Addons\SeoContentAi\Services\RunEngine\ContentProjectRunEngine;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use App\Support\RuntimeLogger;
use InvalidArgumentException;

final class RerunProjectItemStepHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SeoProjectWorkflowRunService $workflowRunService,
        private readonly ContentProjectDomainEvents $domainEvents,
        private readonly ContentProjectGenerationRecoveryService $generationRecovery,
        private readonly ContentProjectRunEngine $runEngine,
        private readonly ContentProjectRerunEligibilityGuard $eligibility,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RerunProjectItemStepCommand) {
            throw new InvalidArgumentException('Expected RerunProjectItemStepCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null || $project->isArchive()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived — step rerun blocked.',
                    $projectId,
                );
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Step rerun requires explicit item selection.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            foreach ($itemIds as $itemId) {
                $task = \App\Addons\SeoContentAi\Models\SeoProjectTask::query()->find((int) $itemId);
                if ($task instanceof \App\Addons\SeoContentAi\Models\SeoProjectTask) {
                    $this->generationRecovery->recoverTaskIfStale($task);
                }
            }

            // Validate BEFORE any run / run_item / status mutation.
            $gate = $this->eligibility->validateStep(
                $project,
                $itemIds,
                $command->fromStep,
                $command->includeDownstream,
            );
            if (! $gate['ok']) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    $gate['message'],
                    $projectId,
                    metadata: [
                        'rejected' => $gate['rejected'],
                        'rerun_from_step' => $command->fromStep->value,
                    ],
                );
            }

            $itemIds = $gate['eligible_ids'];
            $settings = [
                'task_ids' => $itemIds,
                'rerun' => true,
                'rerun_scope' => 'step',
                'rerun_from_step' => $command->fromStep->value,
                'rerun_include_downstream' => $command->includeDownstream,
                'rerun_sync' => $command->syncExecution,
                'use_php_engine' => true,
            ];
            if ($command->sourceArticleId !== null && $command->sourceArticleId > 0) {
                $settings['source_article_id'] = $command->sourceArticleId;
                $settings['article_id'] = $command->sourceArticleId;
            }

            $run = $this->businessLock->withLock(
                $this->businessLock->projectGenerate($projectId),
                function () use ($project, $projectId, $command, $itemIds, $settings): SeoProjectRun {
                    // Re-check conflict inside lock.
                    foreach ($itemIds as $itemId) {
                        if ($this->eligibility->hasConflictingActiveExecution($projectId, (int) $itemId)) {
                            throw new InvalidArgumentException(
                                'Active conflicting execution — step rerun blocked.',
                            );
                        }
                    }

                    $run = $this->workflowRunService->startRun($project, $command->mode, $settings);
                    $limit = $command->mode === SeoProjectRun::MODE_TEST
                        ? SeoProjectWorkflowRunService::TEST_RUN_LIMIT
                        : null;
                    $run = $this->workflowRunService->prepareRunQueue($project, $run, $limit);

                    $executionRef = ContentProjectPublicRef::execution((int) $run->getKey());
                    $this->domainEvents->dispatchAfterCommit(new ContentProjectGenerationRequested(
                        $projectId,
                        $executionRef,
                        $itemIds,
                    ));

                    return $run;
                },
            );

            try {
                $this->runEngine->start($run);
            } catch (\Throwable $e) {
                RuntimeLogger::report($e, [
                    'endpoint' => 'content_project.rerun_step_engine_start',
                    'project_id' => $projectId,
                    'run_id' => (int) $run->getKey(),
                    'task_ids' => $itemIds,
                    'rerun_from_step' => $command->fromStep->value,
                ]);

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    'Step rerun queue prepared but engine start failed: '.$e->getMessage(),
                    $projectId,
                    affectedItemIds: $itemIds,
                    metadata: [
                        'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                        'engine_started' => false,
                        'rerun_from_step' => $command->fromStep->value,
                    ],
                );
            }

            RuntimeLogger::info('content_project.rerun_step_started', [
                'project_id' => $projectId,
                'run_id' => (int) $run->getKey(),
                'task_ids' => $itemIds,
                'rerun_from_step' => $command->fromStep->value,
                'include_downstream' => $command->includeDownstream,
            ]);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                'Step rerun ('.$command->fromStep->value.') started for '.count($itemIds).' item(s).',
                $projectId,
                $itemIds,
                metadata: [
                    'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                    'task_ids' => $itemIds,
                    'engine_started' => true,
                    'rerun_from_step' => $command->fromStep->value,
                    'rerun_include_downstream' => $command->includeDownstream,
                ],
            );
        });
    }
}
