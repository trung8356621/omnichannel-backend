<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\CreateArticlesFromTaskService;
use App\Addons\SeoContentAi\Services\TaskTestInputResolver;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Illuminate\Database\Eloquent\Builder;

/**
 * Generate article content for a content-project task via existing publish workflow.
 * Side effects after generate (WP / notify / SEO chain) phải đi qua event → rule.
 */
final class ArticleGenerateContentHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly CreateArticlesFromTaskService $articleRunner,
        private readonly TaskTestInputResolver $inputResolver,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $taskId = (int) ($input['task_id'] ?? 0);
        if ($taskId <= 0) {
            return AutomationActionResult::failure('INVALID_TASK_ID', 'task_id is required.');
        }

        $task = SeoProjectTask::query()->with('project')->find($taskId);
        if (! $task instanceof SeoProjectTask) {
            return AutomationActionResult::failure('TASK_NOT_FOUND', 'Task not found.');
        }

        $siteId = (int) ($task->site_id ?? $task->project?->site_id ?? $context->siteId ?? 0);
        if ($siteId <= 0) {
            return AutomationActionResult::failure('INVALID_SITE_ID', 'Task missing site_id.');
        }

        try {
            $scope = static function (Builder $builder) use ($siteId): void {
                if (SeoAccessControl::shouldScopeToAccountOwner()) {
                    SeoAccessControl::applyAccessibleSiteScope($builder);
                }
                if ($siteId > 0) {
                    $builder->where('site_id', $siteId);
                }
            };
            $taskContext = $this->inputResolver->resolveForProjectTask($task, $scope);
            $result = $this->articleRunner->runPublishWorkflowForContext($taskContext, $siteId);
        } catch (\Throwable $e) {
            return AutomationActionResult::failure('GENERATE_EXCEPTION', $e->getMessage());
        }

        if (! ($result['success'] ?? false)) {
            return AutomationActionResult::failure(
                'GENERATE_FAILED',
                (string) ($result['message'] ?? 'Content generation failed.'),
                [
                    'task_id' => $taskId,
                    'article_id' => $result['article_id'] ?? null,
                    'steps' => $result['steps'] ?? [],
                ],
            );
        }

        return AutomationActionResult::success(
            output: [
                'task_id' => $taskId,
                'article_id' => $result['article_id'] ?? null,
                'message' => $result['message'] ?? 'Content generated.',
                'steps' => $result['steps'] ?? [],
            ],
            message: 'Content generation completed.',
        );
    }
}
