<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\SeoProjectRunErrorFormatter;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

final class SeoProjectWorkflowRunService
{
    public const TEST_RUN_LIMIT = 3;

    public function __construct(
        private readonly TaskTestInputResolver $inputResolver,
        private readonly CreateArticlesFromTaskService $articleRunner,
        private readonly SeoProjectRunErrorFormatter $errorFormatter,
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

        foreach ($tasks as $task) {
            /** @var SeoProjectTask $task */
            $items[] = $this->runOneTask($project, $run, $task, $projectSiteId);
        }

        return $this->finalizeRun($run, $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryTask(SeoProjectRun $run, int $taskId): array
    {
        @set_time_limit(0);

        $run->loadMissing('project.site');
        $project = $run->project;
        if (! $project instanceof SeoProject) {
            throw new \InvalidArgumentException('Không tìm thấy dự án của lần run này.');
        }

        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereKey($taskId)
            ->first();

        if (! $task instanceof SeoProjectTask) {
            throw new \InvalidArgumentException('Không tìm thấy hạng mục #' . $taskId . ' trong dự án.');
        }

        $projectSiteId = (int) ($project->site_id ?? 0);
        $itemRow = $this->runOneTask($project, $run, $task, $projectSiteId);

        $items = is_array($run->items) ? $run->items : [];
        $replaced = false;

        foreach ($items as $index => $existing) {
            if ((int) ($existing['task_id'] ?? 0) === $taskId) {
                $items[$index] = $itemRow;
                $replaced = true;

                break;
            }
        }

        if (! $replaced) {
            $items[] = $itemRow;
        }

        $this->finalizeRun($run, array_values($items));

        return $itemRow;
    }

    /**
     * @return array<string, mixed>
     */
    private function runOneTask(SeoProject $project, SeoProjectRun $run, SeoProjectTask $task, int $projectSiteId): array
    {
        $taskSiteId = (int) ($task->site_id ?? $projectSiteId);
        if ($taskSiteId <= 0) {
            $task->update(['status' => SeoProjectTask::STATUS_FAILED]);

            return $this->buildFailedItemRow(
                $task,
                null,
                $this->errorFormatter->fromPlainDetail('Thiếu site_id.'),
            );
        }

        $task->update(['status' => SeoProjectTask::STATUS_WRITING]);
        $scope = $this->articleScopeForProject($projectSiteId);

        try {
            $context = $this->inputResolver->resolveForProjectTask($task, $scope);
            $result = $this->articleRunner->runPublishWorkflowForContext($context, $taskSiteId);

            if ($result['success']) {
                $articleId = (int) ($result['article_id'] ?? 0);
                $task->update(['status' => SeoProjectTask::STATUS_COMPLETED]);

                if ($articleId > 0) {
                    $this->storeArticleRunMeta($articleId, $run, $task);
                }

                return $this->buildItemRow($task, true, $articleId, (string) $result['message']);
            }

            $task->update(['status' => SeoProjectTask::STATUS_FAILED]);

            $failedStep = is_array($result['failed_step'] ?? null) ? $result['failed_step'] : null;

            return $this->buildFailedItemRow(
                $task,
                isset($result['article_id']) ? (int) $result['article_id'] : null,
                $this->errorFormatter->fromWorkflowFailure((string) $result['message'], $failedStep),
            );
        } catch (\Throwable $exception) {
            $task->update(['status' => SeoProjectTask::STATUS_FAILED]);

            return $this->buildFailedItemRow($task, null, $this->errorFormatter->fromThrowable($exception));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function finalizeRun(SeoProjectRun $run, array $items): SeoProjectRun
    {
        $succeeded = collect($items)->where('status', 'success')->count();
        $failed = collect($items)->where('status', 'failed')->count();

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
    private function buildItemRow(
        SeoProjectTask $task,
        bool $success,
        ?int $articleId,
        string $message,
    ): array {
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

    /**
     * @param  array{
     *     message: string,
     *     error_detail: string,
     *     error_class: ?string,
     *     error_trace: ?string,
     *     failed_step: ?array{title: string, prompt_name: string, message: string}
     * }  $error
     * @return array<string, mixed>
     */
    private function buildFailedItemRow(SeoProjectTask $task, ?int $articleId, array $error): array
    {
        $row = $this->buildItemRow($task, false, $articleId, $error['message']);

        $row['error_detail'] = $error['error_detail'];

        if (filled($error['error_class'] ?? null)) {
            $row['error_class'] = $error['error_class'];
        }

        if (filled($error['error_trace'] ?? null)) {
            $row['error_trace'] = $error['error_trace'];
        }

        if (is_array($error['failed_step'] ?? null)) {
            $row['failed_step'] = $error['failed_step'];
        }

        return $row;
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
