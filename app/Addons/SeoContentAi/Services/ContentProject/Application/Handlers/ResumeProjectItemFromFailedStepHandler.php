<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers;

use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectFailedStepResumeResolver;
use InvalidArgumentException;

/**
 * Canonical «Tiếp tục từ bước lỗi» — resolve failed step then step-rerun (no full graph).
 */
final class ResumeProjectItemFromFailedStepHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectFailedStepResumeResolver $resumeResolver,
        private readonly RerunProjectItemStepHandler $stepHandler,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ResumeProjectItemFromFailedStepCommand) {
            throw new InvalidArgumentException('Expected ResumeProjectItemFromFailedStepCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Resume requires explicit item_refs — empty selection fail-closed.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            $plans = [];
            foreach ($itemIds as $itemId) {
                $task = SeoProjectTask::query()->find((int) $itemId);
                if (! $task instanceof SeoProjectTask) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        'Task #'.$itemId.' not found — resume fail-closed.',
                        $projectId,
                    );
                }
                $plan = $this->resumeResolver->resolve($task);
                if (! ($plan['ok'] ?? false) || $plan['from_step'] === null) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        (string) ($plan['message'] ?? 'Cannot resolve failed step — resume fail-closed.'),
                        $projectId,
                        metadata: [
                            'item_id' => (int) $itemId,
                            'failed_step_key' => $plan['failed_step_key'] ?? null,
                        ],
                    );
                }
                $plans[(int) $itemId] = $plan;
            }

            $fromSteps = array_unique(array_map(
                static fn (array $p): string => (string) $p['from_step']->value,
                $plans,
            ));
            if (count($fromSteps) !== 1) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Mixed failed steps across items — resume one item at a time.',
                    $projectId,
                    metadata: ['from_steps' => array_values($fromSteps)],
                );
            }

            $firstPlan = $plans[(int) $itemIds[0]];
            $stepResult = $this->stepHandler->handle(new RerunProjectItemStepCommand(
                $command->projectRef,
                $itemIds,
                $firstPlan['from_step'],
                (bool) $firstPlan['include_downstream'],
                null,
                $command->mode,
                false,
            ), $actor);

            $meta = is_array($stepResult->metadata) ? $stepResult->metadata : [];
            $meta['resumed_from_step'] = $firstPlan['resumed_from_step'];
            $meta['reused_steps'] = $firstPlan['reused_steps'];
            $meta['invalidated_steps'] = $firstPlan['invalidated_steps'];
            $meta['failed_step_key'] = $firstPlan['failed_step_key'];
            $meta['prior_run_item_id'] = $firstPlan['run_item_id'];
            $meta['prior_attempt'] = $firstPlan['attempt'];
            $meta['operation_id'] = $meta['execution_ref'] ?? null;
            $meta['new_attempt'] = true;

            if (! $stepResult->success) {
                return ContentProjectActionResult::fail(
                    $stepResult->code,
                    $stepResult->message,
                    $projectId,
                    affectedItemIds: $itemIds,
                    metadata: $meta,
                );
            }

            $label = $firstPlan['from_step']->value === 'article' ? 'Viết bài' : 'Dàn ý';

            return ContentProjectActionResult::ok(
                $stepResult->code,
                'Đã tiếp tục từ bước '.$label.'; reuse upstream: '.implode(', ', $firstPlan['reused_steps'] ?: ['—']).'.',
                $projectId,
                $itemIds,
                metadata: $meta,
            );
        });
    }
}
