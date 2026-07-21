<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Actions\Project;

use App\Addons\SeoContentAi\Automation\Contracts\BusinessAction;
use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionDefinition;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Data\EventEnvelope;
use App\Addons\SeoContentAi\Automation\Enums\ActionRiskLevel;
use App\Addons\SeoContentAi\Automation\Enums\ActionSelectability;
use App\Addons\SeoContentAi\Automation\Enums\ActionSideEffect;
use App\Addons\SeoContentAi\Automation\Support\ActionSupport;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\SeoProjectArticleOwnerSyncService;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors SeoProjectWorkflowRunService::markTaskCompleted local logic without wrapping the run orchestrator.
 */
final class MarkProjectTaskCompletedAction implements BusinessAction
{
    public function __construct(
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'project.task.mark_completed',
            name: 'Mark project task completed',
            description: 'Mark SeoProjectTask completed and keep article link (local).',
            module: 'project',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Low,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'task_id' => ['type' => 'integer', 'required' => true],
                'article_id' => ['type' => 'integer', 'required' => false],
            ],
            outputSchema: [
                'task_id' => ['type' => 'integer'],
                'article_id' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
            ],
            idempotent: true,
            emittedEvents: ['project.task_completed'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $taskId = (int) ($input['task_id'] ?? 0);
        $task = SeoProjectTask::query()->find($taskId);
        if (! $task instanceof SeoProjectTask) {
            return ActionResult::failure('task_not_found', "Task [{$taskId}] not found.");
        }

        $articleId = (int) ($input['article_id'] ?? $task->article_id ?? 0);

        DB::connection($task->getConnectionName())->transaction(function () use ($task, $taskId, $articleId): void {
            if ($articleId > 0) {
                SeoProjectTask::query()
                    ->where('article_id', $articleId)
                    ->whereKeyNot($taskId)
                    ->update(['article_id' => null]);
            }

            $payload = [
                'status' => SeoProjectTask::STATUS_COMPLETED,
                'article_id' => $articleId > 0 ? $articleId : null,
            ];

            if ($articleId > 0 && $task->connected_at === null) {
                $payload['connected_at'] = now();
            }

            if ($task->completed_at === null) {
                $payload['completed_at'] = now();
            }

            SeoProjectTask::query()->whereKey($taskId)->update($payload);

            if ($articleId > 0) {
                $task->loadMissing('project');
                if ($task->project instanceof SeoProject) {
                    $this->articleOwnerSync->assignWriterToArticle($task->project, $articleId);
                }
            }
        });

        return ActionResult::success(
            output: [
                'task_id' => $taskId,
                'article_id' => $articleId > 0 ? $articleId : null,
                'status' => SeoProjectTask::STATUS_COMPLETED,
            ],
            events: [
                EventEnvelope::make(
                    eventKey: 'project.task_completed',
                    entity: ['type' => 'project_task', 'id' => $taskId],
                    context: [
                        'correlation_id' => $context->correlationId,
                        'origin' => $context->origin,
                        'actor_id' => $context->actorId,
                        'site_id' => $context->siteId,
                        'suppress_article_completed_bridge' => $context->origin === 'content_project_run',
                    ],
                    payload: ['article_id' => $articleId > 0 ? $articleId : null],
                ),
            ],
            changed: ['project_task.status'],
        );
    }
}
