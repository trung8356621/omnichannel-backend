<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Actions\Project;

use App\Addons\SeoContentAi\Automation\Contracts\BusinessAction;
use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionDefinition;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Enums\ActionRiskLevel;
use App\Addons\SeoContentAi\Automation\Enums\ActionSelectability;
use App\Addons\SeoContentAi\Automation\Enums\ActionSideEffect;
use App\Addons\SeoContentAi\Automation\Support\ActionSupport;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use Illuminate\Support\Facades\DB;

final class AttachArticleToProjectTaskAction implements BusinessAction
{
    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'project.task.attach_article',
            name: 'Attach article to task',
            description: 'Link article_id onto project task (local).',
            module: 'project',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'task_id' => ['type' => 'integer', 'required' => true],
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            outputSchema: [
                'task_id' => ['type' => 'integer'],
                'article_id' => ['type' => 'integer'],
            ],
            idempotent: true,
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $taskId = (int) ($input['task_id'] ?? 0);
        $articleId = (int) ($input['article_id'] ?? 0);
        $task = SeoProjectTask::query()->find($taskId);
        if (! $task instanceof SeoProjectTask) {
            return ActionResult::failure('task_not_found', "Task [{$taskId}] not found.");
        }

        if (ActionSupport::findArticle($articleId) === null) {
            return ActionResult::failure('article_not_found', "Article [{$articleId}] not found.");
        }

        DB::connection($task->getConnectionName())->transaction(function () use ($task, $taskId, $articleId): void {
            SeoProjectTask::query()
                ->where('article_id', $articleId)
                ->whereKeyNot($taskId)
                ->update(['article_id' => null]);

            $payload = ['article_id' => $articleId];
            if ($task->connected_at === null) {
                $payload['connected_at'] = now();
            }

            SeoProjectTask::query()->whereKey($taskId)->update($payload);
        });

        return ActionResult::success(
            output: [
                'task_id' => $taskId,
                'article_id' => $articleId,
            ],
            changed: ['project_task.article_id'],
        );
    }
}
