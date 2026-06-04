<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

final class SeoProjectWorkflowRunService
{
    public const TEST_RUN_LIMIT = 3;

    public function __construct(
        private readonly TaskTestInputResolver $inputResolver,
        private readonly CreateArticlesFromTaskService $articleRunner,
    ) {}

    public function startRun(SeoProject $project, string $mode): SeoProjectRun
    {
        return SeoProjectRun::query()->create([
            'project_id' => (int) $project->id,
            'user_id' => (int) auth()->id(),
            'mode' => $mode === SeoProjectRun::MODE_TEST ? SeoProjectRun::MODE_TEST : SeoProjectRun::MODE_FULL,
            'status' => SeoProjectRun::STATUS_RUNNING,
            'total' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'items' => [],
            'started_at' => now(),
        ]);
    }

    public function execute(SeoProject $project, SeoProjectRun $run, ?int $limit = null): SeoProjectRun
    {
        @set_time_limit(0);

        $project->loadMissing('site');
        $projectSiteId = (int) ($project->site_id ?? 0);

        $query = $project->tasks()
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->orderBy('target_date')
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $tasks = $query->get();
        $items = [];
        $succeeded = 0;
        $failed = 0;

        $scope = $this->articleScopeForProject($projectSiteId);

        foreach ($tasks as $task) {
            /** @var SeoProjectTask $task */
            $taskSiteId = (int) ($task->site_id ?? $projectSiteId);
            if ($taskSiteId <= 0) {
                $items[] = $this->buildItemRow($task, false, null, 'Thiếu site_id.');
                $failed++;
                $task->update(['status' => SeoProjectTask::STATUS_FAILED]);

                continue;
            }

            $task->update(['status' => SeoProjectTask::STATUS_WRITING]);

            try {
                $context = $this->inputResolver->resolveForProjectTask($task, $scope);
                $result = $this->articleRunner->runPublishWorkflowForContext($context, $taskSiteId);

                if ($result['success']) {
                    $articleId = (int) ($result['article_id'] ?? 0);
                    $items[] = $this->buildItemRow($task, true, $articleId, (string) $result['message']);
                    $succeeded++;
                    $task->update(['status' => SeoProjectTask::STATUS_COMPLETED]);

                    if ($articleId > 0) {
                        $this->storeArticleRunMeta($articleId, $run, $task);
                    }
                } else {
                    $items[] = $this->buildItemRow(
                        $task,
                        false,
                        isset($result['article_id']) ? (int) $result['article_id'] : null,
                        (string) $result['message'],
                    );
                    $failed++;
                    $task->update(['status' => SeoProjectTask::STATUS_FAILED]);
                }
            } catch (\Throwable $exception) {
                $items[] = $this->buildItemRow($task, false, null, $exception->getMessage());
                $failed++;
                $task->update(['status' => SeoProjectTask::STATUS_FAILED]);
            }
        }

        $run->update([
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'total' => count($items),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'items' => $items,
            'finished_at' => now(),
        ]);

        return $run->fresh(['project']);
    }

    /**
     * @return null|callable(Builder): void
     */
    private function articleScopeForProject(int $projectSiteId): ?callable
    {
        return function (Builder $builder) use ($projectSiteId): void {
            if (auth()->user()?->role !== 'admin') {
                $builder->whereIn(
                    'site_id',
                    Site::query()->where('user_id', auth()->id())->select('id'),
                );
            }

            if ($projectSiteId > 0) {
                $builder->where('site_id', $projectSiteId);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItemRow(SeoProjectTask $task, bool $success, ?int $articleId, string $message): array
    {
        return [
            'task_id' => (int) $task->id,
            'type' => (string) $task->type,
            'source_content' => (string) $task->source_content,
            'post_type' => $task->type === SeoProjectTask::TYPE_NEW_KEYWORD
                ? SeoProjectTask::normalizePostType($task->post_type)
                : null,
            'target_date' => $task->target_date?->format('Y-m-d'),
            'status' => $success ? 'success' : 'failed',
            'article_id' => $articleId,
            'article_edit_url' => $articleId > 0 ? ArticleResource::getUrl('edit', ['record' => $articleId]) : null,
            'message' => $message,
        ];
    }

    private function storeArticleRunMeta(int $articleId, SeoProjectRun $run, SeoProjectTask $task): void
    {
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'content_project_run'],
            [
                'meta_value' => json_encode([
                    'run_id' => (int) $run->id,
                    'project_id' => (int) $run->project_id,
                    'task_id' => (int) $task->id,
                    'ran_at' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
            ],
        );
    }
}
