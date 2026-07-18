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
use Illuminate\Support\Facades\DB;

final class CreateProjectTaskAction implements BusinessAction
{
    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'project.task.create',
            name: 'Create project task',
            description: 'Create a SeoProjectTask row (local).',
            module: 'project',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'project_id' => ['type' => 'integer', 'required' => true],
                'type' => ['type' => 'string', 'required' => false],
                'source_content' => ['type' => 'string', 'required' => false],
                'article_id' => ['type' => 'integer', 'required' => false],
                'post_type' => ['type' => 'string', 'required' => false],
                'rewrite_mode' => ['type' => 'string', 'required' => false],
            ],
            outputSchema: [
                'task_id' => ['type' => 'integer'],
                'project_id' => ['type' => 'integer'],
            ],
            emittedEvents: ['project.task_created'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $projectId = (int) ($input['project_id'] ?? 0);
        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return ActionResult::failure('project_not_found', "Project [{$projectId}] not found.");
        }

        if (! $project->isExecutionMonthOpen()) {
            return ActionResult::failure('project_month_closed', 'Project execution month is not open.');
        }

        if (! $project->canRegisterMoreTasks()) {
            return ActionResult::failure('project_capacity_full', 'Project has no remaining task capacity.');
        }

        $type = trim((string) ($input['type'] ?? SeoProjectTask::TYPE_NEW_KEYWORD));
        if (! in_array($type, SeoProjectTask::typeKeys(), true)) {
            $type = SeoProjectTask::TYPE_NEW_KEYWORD;
        }

        $sourceContent = trim((string) ($input['source_content'] ?? ''));
        $articleId = (int) ($input['article_id'] ?? 0);
        $siteId = (int) ($project->site_id ?? 0);

        $output = DB::connection($project->getConnectionName())->transaction(function () use (
            $project,
            $type,
            $sourceContent,
            $articleId,
            $siteId,
            $input,
        ): array {
            $payload = [
                'project_id' => (int) $project->id,
                'site_id' => $siteId > 0 ? $siteId : null,
                'type' => $type,
                'source_content' => $sourceContent,
                'description' => null,
                'target_date' => $project->monthCarbon()->format('Y-m-d'),
                'status' => SeoProjectTask::STATUS_PENDING,
                'article_id' => $articleId > 0 ? $articleId : null,
            ];

            if (SeoProjectTask::isNewArticleType($type)) {
                $payload['post_type'] = SeoProjectTask::normalizePostType(
                    (string) ($input['post_type'] ?? SeoProjectTask::POST_TYPE_ARTICLE),
                );
                $payload['article_id'] = null;
            }

            if ($type === SeoProjectTask::TYPE_REWRITE) {
                $payload['rewrite_mode'] = SeoProjectTask::normalizeRewriteMode(
                    $input['rewrite_mode'] ?? null,
                );
            }

            $task = SeoProjectTask::query()->create($payload);
            $project->syncTotalTasksCounter();

            return [
                'task_id' => (int) $task->id,
                'project_id' => (int) $project->id,
                'type' => $type,
                'article_id' => $payload['article_id'],
            ];
        });

        return ActionResult::success(
            output: $output,
            events: [
                EventEnvelope::make(
                    eventKey: 'project.task_created',
                    entity: ['type' => 'project_task', 'id' => $output['task_id']],
                    context: [
                        'correlation_id' => $context->correlationId,
                        'origin' => $context->origin,
                        'site_id' => $context->siteId ?? $siteId,
                        'actor_id' => $context->actorId,
                    ],
                    payload: [
                        'project_id' => $output['project_id'],
                        'type' => $output['type'],
                    ],
                ),
            ],
            changed: ['project_task'],
        );
    }
}
