<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SeoProjectRunErrorFormatter;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function ensureFailedTasksQueued(SeoProjectRun $run): void
    {
        $run->loadMissing('project.site');
        $project = $run->project;
        if (! $project instanceof SeoProject) {
            return;
        }

        $items = is_array($run->items) ? $run->items : [];
        $changed = false;

        foreach ($items as $index => $item) {
            if (! is_array($item) || (string) ($item['status'] ?? '') !== 'failed') {
                continue;
            }

            $pending = $this->matchingTaskQuery($project, $item)
                ->where('status', SeoProjectTask::STATUS_PENDING)
                ->orderBy('id')
                ->first();

            if (! $pending instanceof SeoProjectTask) {
                $failedTask = $this->matchingTaskQuery($project, $item)
                    ->where('status', SeoProjectTask::STATUS_FAILED)
                    ->orderBy('id')
                    ->first();

                $pending = $failedTask instanceof SeoProjectTask
                    ? SeoProjectTask::query()->find($this->enqueueFailedTaskOnce($project, $failedTask))
                    : $this->createRetryTaskFromItem($project, $item);
            }

            $retryTaskId = (int) ($pending?->id ?? 0);
            if ($retryTaskId > 0 && (int) ($item['retry_task_id'] ?? 0) !== $retryTaskId) {
                $items[$index]['retry_task_id'] = $retryTaskId;
                $changed = true;
            }
        }

        if ($changed) {
            $run->update(['items' => array_values($items)]);
        }
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
            throw new \InvalidArgumentException('Không tìm thấy hạng mục #'.$taskId.' trong dự án.');
        }

        $existingItem = collect(is_array($run->items) ? $run->items : [])
            ->first(fn (mixed $item): bool => is_array($item) && (int) ($item['task_id'] ?? 0) === $taskId);

        $retryTaskId = is_array($existingItem)
            ? (int) ($existingItem['retry_task_id'] ?? 0)
            : 0;
        if ($retryTaskId > 0) {
            $retryTask = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->whereKey($retryTaskId)
                ->first();

            if ($retryTask instanceof SeoProjectTask) {
                $task = $retryTask;
            }
        }

        $projectSiteId = (int) ($project->site_id ?? 0);
        $itemRow = $this->runOneTask($project, $run, $task, $projectSiteId);
        $itemRow['task_id'] = $taskId;
        unset($itemRow['retry_task_id']);

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
    public function markTaskFixed(SeoProjectRun $run, int $taskId, ?int $articleId = null): array
    {
        $run->loadMissing('project.site');
        $project = $run->project;
        if (! $project instanceof SeoProject) {
            throw new \InvalidArgumentException('Không tìm thấy dự án của lần run này.');
        }

        $items = is_array($run->items) ? $run->items : [];
        $existingIndex = null;
        $existingItem = null;

        foreach ($items as $index => $item) {
            if (is_array($item) && (int) ($item['task_id'] ?? 0) === $taskId) {
                $existingIndex = $index;
                $existingItem = $item;
                break;
            }
        }

        if (! is_array($existingItem)) {
            throw new \InvalidArgumentException('Không tìm thấy hạng mục #'.$taskId.' trong kết quả run.');
        }

        $resolvedArticleId = $articleId ?: (int) ($existingItem['article_id'] ?? 0);
        $articleExists = $resolvedArticleId > 0
            && SeoArticle::query()
                ->whereKey($resolvedArticleId)
                ->where('site_id', (int) $project->site_id)
                ->exists();

        if (! $articleExists) {
            throw new \InvalidArgumentException('Không tìm thấy bài viết đã sửa để đánh dấu hoàn thành.');
        }

        $taskIds = array_values(array_filter([
            (int) ($existingItem['task_id'] ?? 0),
            (int) ($existingItem['retry_task_id'] ?? 0),
        ]));
        $currentTasks = $project->tasks()
            ->whereIn('id', $taskIds)
            ->get();

        if ($currentTasks->isEmpty()) {
            $currentTasks = $this->matchingTaskQuery($project, $existingItem)
                ->whereIn('status', [SeoProjectTask::STATUS_PENDING, SeoProjectTask::STATUS_FAILED])
                ->get();
        }

        foreach ($currentTasks as $index => $currentTask) {
            $currentTask->update([
                'status' => SeoProjectTask::STATUS_COMPLETED,
                'article_id' => $index === 0 ? $resolvedArticleId : null,
            ]);
        }

        $metaTask = $currentTasks->first();
        if ($metaTask instanceof SeoProjectTask) {
            $this->storeArticleRunMeta($resolvedArticleId, $run, $metaTask);
        }

        $itemRow = array_merge($existingItem, [
            'status' => 'success',
            'article_id' => $resolvedArticleId,
            'article_edit_url' => ArticleResource::panelUrl('edit', ['record' => $resolvedArticleId]),
            'message' => 'Đã sửa lỗi thủ công.',
        ]);
        $itemRow['manual_fixed'] = true;
        unset(
            $itemRow['error_detail'],
            $itemRow['error_class'],
            $itemRow['error_trace'],
            $itemRow['failed_step'],
            $itemRow['retry_task_id'],
        );

        if ($existingIndex === null) {
            $items[] = $itemRow;
        } else {
            $items[$existingIndex] = $itemRow;
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
                $task->update([
                    'status' => SeoProjectTask::STATUS_COMPLETED,
                    'article_id' => $articleId > 0 ? $articleId : null,
                ]);

                if ($articleId > 0) {
                    $this->storeArticleRunMeta($articleId, $run, $task);
                }

                return $this->buildItemRow(
                    $task,
                    true,
                    $articleId,
                    (string) $result['message'],
                    $this->promptSteps($result['steps'] ?? []),
                );
            }

            $task->update(['status' => SeoProjectTask::STATUS_FAILED]);

            $failedStep = is_array($result['failed_step'] ?? null) ? $result['failed_step'] : null;

            $item = $this->buildFailedItemRow(
                $task,
                isset($result['article_id']) ? (int) $result['article_id'] : null,
                $this->errorFormatter->fromWorkflowFailure((string) $result['message'], $failedStep),
                $this->promptSteps($result['steps'] ?? []),
            );
            $item['retry_task_id'] = $this->enqueueFailedTaskOnce($project, $task);

            return $item;
        } catch (\Throwable $exception) {
            $task->update(['status' => SeoProjectTask::STATUS_FAILED]);

            $item = $this->buildFailedItemRow($task, null, $this->errorFormatter->fromThrowable($exception));
            $item['retry_task_id'] = $this->enqueueFailedTaskOnce($project, $task);

            return $item;
        }
    }

    /**
     * Add one pending copy for a failed task. Existing pending copies are reused.
     */
    private function enqueueFailedTaskOnce(SeoProject $project, SeoProjectTask $failedTask): int
    {
        $pending = $this->matchingTaskQuery($project, [
            'type' => (string) $failedTask->type,
            'source_content' => (string) $failedTask->source_content,
            'post_type' => $failedTask->post_type,
        ])
            ->where('id', '!=', (int) $failedTask->id)
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->orderBy('id')
            ->first();

        if ($pending instanceof SeoProjectTask) {
            return (int) $pending->id;
        }

        $retryTask = $project->tasks()->create([
            'site_id' => $failedTask->site_id ?: $project->site_id,
            'type' => $failedTask->type,
            'post_type' => $failedTask->type === SeoProjectTask::TYPE_NEW_KEYWORD
                ? SeoProjectTask::normalizePostType($failedTask->post_type)
                : null,
            'source_content' => $failedTask->source_content,
            'description' => $failedTask->description,
            'target_date' => $failedTask->target_date,
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);

        return (int) $retryTask->id;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createRetryTaskFromItem(SeoProject $project, array $item): SeoProjectTask
    {
        return $project->tasks()->create([
            'site_id' => (int) $project->site_id,
            'type' => (string) ($item['type'] ?? SeoProjectTask::TYPE_NEW_KEYWORD),
            'post_type' => (string) ($item['type'] ?? '') === SeoProjectTask::TYPE_NEW_KEYWORD
                ? SeoProjectTask::normalizePostType($item['post_type'] ?? null)
                : null,
            'source_content' => trim((string) ($item['source_content'] ?? '')),
            'description' => null,
            'target_date' => $item['target_date'] ?? null,
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matchingTaskQuery(SeoProject $project, array $item): HasMany
    {
        $type = (string) ($item['type'] ?? SeoProjectTask::TYPE_NEW_KEYWORD);
        $source = trim((string) ($item['source_content'] ?? ''));

        $query = $project->tasks()
            ->where('type', $type)
            ->where('source_content', $source);

        if ($type === SeoProjectTask::TYPE_NEW_KEYWORD) {
            $query->where(
                'post_type',
                SeoProjectTask::normalizePostType($item['post_type'] ?? null),
            );
        }

        return $query;
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
                    Site::query()
                        ->where('user_id', SeoAccessControl::accountOwnerId() ?? (int) auth()->id())
                        ->select('id'),
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
        array $steps = [],
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
            'article_edit_url' => $articleId > 0 ? ArticleResource::panelUrl('edit', ['record' => $articleId]) : null,
            'message' => $message,
            'steps' => $steps,
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
    private function buildFailedItemRow(
        SeoProjectTask $task,
        ?int $articleId,
        array $error,
        array $steps = [],
    ): array {
        $row = $this->buildItemRow($task, false, $articleId, $error['message'], $steps);

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

    /**
     * @return list<array<string, mixed>>
     */
    private function promptSteps(mixed $steps): array
    {
        if (! is_array($steps)) {
            return [];
        }

        return collect($steps)
            ->filter(fn (mixed $step): bool => is_array($step) && ($step['type'] ?? '') === 'prompt')
            ->values()
            ->all();
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
