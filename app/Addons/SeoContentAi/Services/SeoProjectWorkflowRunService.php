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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SeoProjectWorkflowRunService
{
    public const TEST_RUN_LIMIT = 1;

    public function __construct(
        private readonly TaskTestInputResolver $inputResolver,
        private readonly CreateArticlesFromTaskService $articleRunner,
        private readonly SeoProjectRunErrorFormatter $errorFormatter,
        private readonly PromptResultLinkService $promptResultLinks,
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
        private readonly ArticleEditorReadinessService $editorReadiness,
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

    public function prepareRunQueue(SeoProject $project, SeoProjectRun $run, ?int $limit = null): SeoProjectRun
    {
        $pendingCount = (int) $project->tasks()
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->count();

        if ($pendingCount <= 0) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.projects.run_items_empty'));
        }

        $plannedTotal = $limit !== null && $limit > 0
            ? min($pendingCount, $limit)
            : $pendingCount;

        $project->loadMissing('site');
        $projectSiteId = (int) ($project->site_id ?? 0);
        $plannedItems = $this->seedPlannedItems($project, $projectSiteId, $limit);

        $run->update([
            'status' => SeoProjectRun::STATUS_RUNNING,
            'total' => count($plannedItems) > 0 ? count($plannedItems) : $plannedTotal,
            'succeeded' => 0,
            'failed' => 0,
            'items' => $plannedItems,
            'finished_at' => null,
        ]);

        $run = $run->fresh(['project']);

        if ($this->pendingAiTasksInBatch($project, $limit) === 0) {
            return $this->completeRunQueue($run);
        }

        return $run;
    }

    private function pendingAiTasksInBatch(SeoProject $project, ?int $limit): int
    {
        $query = $project->tasks()
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->where('type', '!=', SeoProjectTask::TYPE_IMPROVE)
            ->orderBy('target_date')
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return (int) $query->count();
    }

    /**
     * Seed toàn bộ task trong batch vào run.items (pending/manual) để UI không mất hàng giữa chừng.
     *
     * @return list<array<string, mixed>>
     */
    private function seedPlannedItems(SeoProject $project, int $projectSiteId, ?int $limit = null): array
    {
        $query = $project->tasks()
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->orderBy('target_date')
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query
            ->get()
            ->map(function (SeoProjectTask $task) use ($projectSiteId): array {
                if ($task->type === SeoProjectTask::TYPE_IMPROVE) {
                    return $this->buildImproveManualItemRow($task, $projectSiteId);
                }

                return $this->buildPendingItemRow($task);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPendingItemRow(SeoProjectTask $task): array
    {
        return [
            'task_id' => (int) $task->id,
            'type' => (string) $task->type,
            'source_content' => (string) $task->source_content,
            'post_type' => SeoProjectTask::isNewArticleType($task->type)
                ? SeoProjectTask::normalizePostType($task->post_type)
                : null,
            'loai_san_pham' => SeoProjectTask::isNewArticleType($task->type)
                && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                    ? (string) ($task->loai_san_pham ?? '')
                    : null,
            'gallery_description' => SeoProjectTask::isNewArticleType($task->type)
                && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                    ? (string) ($task->description ?? '')
                    : null,
            'target_date' => $task->target_date?->format('Y-m-d'),
            'rewrite_mode' => $task->type === SeoProjectTask::TYPE_REWRITE
                ? SeoProjectTask::normalizeRewriteMode($task->rewrite_mode)
                : null,
            'rewrite_notes' => $task->type === SeoProjectTask::TYPE_REWRITE
                ? $task->rewrite_notes
                : null,
            'status' => 'pending',
            'article_id' => null,
            'article_edit_url' => null,
            'message' => '',
            'steps' => [],
        ];
    }

    /**
     * Gắn lại các task đã completed sau khi run bắt đầu nhưng bị thiếu trong items (run cũ / race).
     */
    public function reconcileMissingCompletedItems(SeoProjectRun $run): SeoProjectRun
    {
        $run->loadMissing('project.site');
        $project = $run->project;
        if (! $project instanceof SeoProject) {
            return $run;
        }

        $items = is_array($run->items) ? $run->items : [];
        $knownTaskIds = collect($items)
            ->map(static fn (mixed $item): int => is_array($item) ? (int) ($item['task_id'] ?? 0) : 0)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $completedQuery = $project->tasks()
            ->where('status', SeoProjectTask::STATUS_COMPLETED)
            ->orderBy('target_date')
            ->orderBy('id');

        if ($knownTaskIds !== []) {
            $completedQuery->whereNotIn('id', $knownTaskIds);
        }

        if ($run->started_at !== null) {
            $completedQuery->where(function ($query) use ($run): void {
                $query->where('updated_at', '>=', $run->started_at)
                    ->orWhere('created_at', '>=', $run->started_at);
            });
        }

        $missing = $completedQuery->get();
        if ($missing->isEmpty()) {
            return $run;
        }

        $projectSiteId = (int) ($project->site_id ?? 0);

        foreach ($missing as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            if ($task->type === SeoProjectTask::TYPE_IMPROVE) {
                $items[] = $this->buildImproveManualItemRow($task, $projectSiteId);
                continue;
            }

            $articleId = (int) ($task->article_id ?? 0);
            $items[] = $this->buildItemRow(
                $task,
                true,
                $articleId > 0 ? $articleId : null,
                'Đã chạy quy trình và tạo/cập nhật bài.',
            );
        }

        $succeeded = collect($items)->where('status', 'success')->count();
        $failed = collect($items)->where('status', 'failed')->count();

        $run->update([
            'items' => array_values($items),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'total' => max((int) $run->total, count($items)),
        ]);

        return $run->fresh(['project.site', 'user', 'project.tasks']) ?? $run;
    }

    public function completeRunQueue(SeoProjectRun $run): SeoProjectRun
    {
        $items = is_array($run->items) ? $run->items : [];

        $run = $this->persistRunItems($run, $items, true);
        $run->loadMissing('project');

        $project = $run->project;
        if (! $project instanceof SeoProject) {
            return $run;
        }

        return app(SeoProjectRunConsolidationService::class)->maybeConsolidate($project) ?? $run;
    }

    /**
     * Đánh dấu run completed + cập nhật counter từ items hiện tại, không consolidate.
     */
    public function markRunCompletedQuietly(SeoProjectRun $run): SeoProjectRun
    {
        $run->refresh();
        $items = is_array($run->items) ? $run->items : [];

        return $this->persistRunItems($run, $items, true);
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

        $consolidation = app(SeoProjectRunConsolidationService::class);

        foreach ($items as $index => $item) {
            if (! is_array($item) || (string) ($item['status'] ?? '') !== 'failed') {
                continue;
            }

            if ($consolidation->hasSuccessfulRunItem($project, $item)) {
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
    public function retryTask(SeoProjectRun $run, int $taskId, bool $markCompleted = true, ?int $forcedArticleId = null): array
    {
        @set_time_limit(0);

        $run->loadMissing('project.site');
        $project = $run->project;
        if (! $project instanceof SeoProject) {
            throw new \InvalidArgumentException('Không tìm thấy dự án của lần run này.');
        }

        // Luôn đọc items mới nhất từ DB trước khi merge — tránh Livewire stale ghi đè mất hàng đã OK.
        $run->refresh();
        $items = is_array($run->items) ? $run->items : [];

        $existingItem = collect($items)
            ->first(fn (mixed $item): bool => is_array($item) && (int) ($item['task_id'] ?? 0) === $taskId);

        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereKey($taskId)
            ->first();

        // Task gốc có thể đã bị gộp/xóa (consolidation). Thử retry_task_id rồi dựng lại từ item.
        $retryTaskId = is_array($existingItem)
            ? (int) ($existingItem['retry_task_id'] ?? 0)
            : 0;
        if (! $task instanceof SeoProjectTask && $retryTaskId > 0) {
            $task = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->whereKey($retryTaskId)
                ->first();
        }

        if (! $task instanceof SeoProjectTask && is_array($existingItem)) {
            $task = $this->createRetryTaskFromItem($project, $existingItem);
            Log::info('seo.project_run.retry.rebuilt_task', [
                'run_id' => (int) $run->id,
                'stale_task_id' => $taskId,
                'rebuilt_task_id' => (int) $task->id,
            ]);
        }

        if (! $task instanceof SeoProjectTask) {
            throw new \InvalidArgumentException('Không tìm thấy hạng mục #'.$taskId.' trong dự án.');
        }

        if ($retryTaskId > 0 && $task->getKey() !== $retryTaskId && $task->getKey() === $taskId) {
            $retryTask = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->whereKey($retryTaskId)
                ->first();

            if ($retryTask instanceof SeoProjectTask) {
                $task = $retryTask;
            }
        }

        // Chạy lại phải bám đúng bài đã tạo/liên kết trước đó — tránh match nhầm bài khác cùng tiêu đề.
        $linkedArticleId = (int) ($forcedArticleId ?? 0);
        if ($linkedArticleId <= 0) {
            $linkedArticleId = (int) ($task->article_id ?? 0);
        }
        if ($linkedArticleId <= 0 && is_array($existingItem)) {
            $linkedArticleId = (int) ($existingItem['article_id'] ?? 0);
        }

        if ($linkedArticleId > 0 && (int) ($task->article_id ?? 0) !== $linkedArticleId) {
            $articleExists = SeoArticle::query()
                ->whereKey($linkedArticleId)
                ->where('site_id', (int) $project->site_id)
                ->exists();

            if ($articleExists) {
                $task->article_id = $linkedArticleId;
                $task->save();

                Log::info('seo.project_run.retry.relink_article', [
                    'run_id' => (int) $run->id,
                    'task_id' => (int) $task->id,
                    'relinked_article_id' => $linkedArticleId,
                ]);
            }
        }

        $projectSiteId = (int) ($project->site_id ?? 0);
        $previousRetryCount = is_array($existingItem) ? (int) ($existingItem['retry_count'] ?? 0) : 0;

        $itemRow = $this->runOneTask($project, $run, $task, $projectSiteId);
        $itemRow['task_id'] = $taskId;
        $itemRow['retry_count'] = $previousRetryCount + 1;
        unset($itemRow['retry_task_id']);

        // Refresh lần nữa sau AI (có thể dài) rồi merge — chống mất item do request song song.
        $run->refresh();
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

        $this->persistRunItems($run, array_values($items), $markCompleted);

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
            if ($index === 0) {
                $this->markTaskCompleted($currentTask, $resolvedArticleId);

                continue;
            }

            $this->persistTaskState($currentTask, SeoProjectTask::STATUS_COMPLETED, null);
        }

        $metaTask = $currentTasks->first();
        if ($metaTask instanceof SeoProjectTask) {
            $this->storeArticleRunMeta($resolvedArticleId, $run, $metaTask);
        }

        $itemRow = array_merge($existingItem, [
            'status' => 'success',
            'article_id' => $resolvedArticleId,
            'article_edit_url' => ArticleResource::getUrl('edit', ['record' => $resolvedArticleId], isAbsolute: false),
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
        if ($task->type === SeoProjectTask::TYPE_IMPROVE) {
            return $this->buildImproveManualItemRow($task, $projectSiteId);
        }

        $taskSiteId = (int) ($task->site_id ?? $projectSiteId);
        if ($taskSiteId <= 0) {
            $this->markTaskFailed($task);

            return $this->buildFailedItemRow(
                $task,
                null,
                $this->errorFormatter->fromPlainDetail('Thiếu site_id.'),
            );
        }

        $this->markTaskWriting($task);
        $scope = $this->articleScopeForProject($projectSiteId);

        try {
            $context = $this->inputResolver->resolveForProjectTask($task, $scope);

            Log::info('seo.project_run.task.start', [
                'run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
                'task_type' => (string) $task->type,
                'article_id' => (int) ($task->article_id ?? 0),
                'context_project_task_type' => $context->projectTaskType,
                'context_rewrite_mode' => $context->rewriteMode,
                'context_post_type' => $context->postType,
                'context_is_new_article' => $context->isNewArticle,
                'context_article_id' => (int) ($context->article?->id ?? 0),
            ]);

            $result = $this->articleRunner->runPublishWorkflowForContext($context, $taskSiteId);
            $steps = is_array($result['steps'] ?? null) ? $result['steps'] : [];
            $stepStats = $this->summarizeStepStats($steps);
            $ranAt = now();

            Log::info('seo.project_run.task.finished', [
                'run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
                'success' => (bool) ($result['success'] ?? false),
                'article_id' => (int) ($result['article_id'] ?? 0),
                'message' => (string) ($result['message'] ?? ''),
                'step_stats' => $stepStats,
                'step_statuses' => collect($steps)
                    ->filter(static fn (mixed $step): bool => is_array($step))
                    ->map(static fn (array $step): array => [
                        'type' => (string) ($step['type'] ?? ''),
                        'title' => (string) ($step['title'] ?? ''),
                        'status' => (string) ($step['status'] ?? ''),
                        'message' => mb_substr((string) ($step['message'] ?? ''), 0, 180),
                    ])
                    ->values()
                    ->all(),
            ]);

            if ($result['success']) {
                $articleId = (int) ($result['article_id'] ?? 0);
                $this->markTaskCompleted($task, $articleId);

                $readiness = null;
                if ($articleId > 0) {
                    try {
                        $this->storeArticleRunMeta($articleId, $run, $task);
                        $this->promptResultLinks->linkFromWorkflowSteps(
                            steps: $steps,
                            articleId: $articleId,
                            runId: (int) $run->id,
                            taskId: (int) $task->id,
                        );
                        $article = SeoArticle::query()->find($articleId);
                        if ($article instanceof SeoArticle) {
                            $readiness = $this->editorReadiness->queueAfterWorkflowRun($article, (int) $run->id);
                        }
                    } catch (\Throwable $sideEffectException) {
                        // Bài + task.article_id đã lưu — không để side-effect làm fail cả hạng mục / xóa link.
                        Log::warning('seo.project_run.task.post_success_side_effect', [
                            'run_id' => (int) $run->id,
                            'task_id' => (int) $task->id,
                            'article_id' => $articleId,
                            'error' => $sideEffectException->getMessage(),
                            'class' => $sideEffectException::class,
                        ]);
                    }
                }

                $message = $this->formatRunResultMessage((string) $result['message'], $ranAt, $stepStats);
                $itemRow = $this->buildItemRow(
                    $task,
                    true,
                    $articleId > 0 ? $articleId : null,
                    $message,
                    $this->promptSteps($steps),
                    $ranAt,
                );
                $itemRow['step_stats'] = $stepStats;
                $itemRow['debug'] = [
                    'project_task_type' => $context->projectTaskType,
                    'rewrite_mode' => $context->rewriteMode,
                    'post_type' => $context->postType,
                    'ai_completed' => $stepStats['completed'],
                    'ai_skipped' => $stepStats['skipped'],
                ];

                if ($readiness !== null) {
                    $itemRow['article_editor_ready'] = $readiness->isReady;
                    $itemRow['article_editor_preparing_message'] = $this->editorReadiness->userMessage($readiness);
                }

                return $itemRow;
            }

            $failedArticleId = (int) ($result['article_id'] ?? 0);
            $this->markTaskFailed($task, $failedArticleId > 0 ? $failedArticleId : null);

            $failedStep = is_array($result['failed_step'] ?? null) ? $result['failed_step'] : null;

            $item = $this->buildFailedItemRow(
                $task,
                $failedArticleId > 0 ? $failedArticleId : null,
                $this->errorFormatter->fromWorkflowFailure((string) $result['message'], $failedStep),
                $this->promptSteps($steps),
                $ranAt,
            );
            $item['step_stats'] = $stepStats;

            if ($failedArticleId > 0) {
                $this->storeArticleRunMeta($failedArticleId, $run, $task);
                $this->promptResultLinks->linkFromWorkflowSteps(
                    steps: $steps,
                    articleId: $failedArticleId,
                    runId: (int) $run->id,
                    taskId: (int) $task->id,
                    source: 'workflow_run_failed',
                );
            }

            $item['retry_task_id'] = $this->enqueueFailedTaskOnce($project, $task);

            return $item;
        } catch (\Throwable $exception) {
            Log::error('seo.project_run.task.exception', [
                'run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
                'error' => $exception->getMessage(),
                'class' => $exception::class,
            ]);

            $keptArticleId = (int) ($task->article_id ?? 0);
            $this->markTaskFailed($task, $keptArticleId > 0 ? $keptArticleId : null);

            $item = $this->buildFailedItemRow(
                $task,
                $keptArticleId > 0 ? $keptArticleId : null,
                $this->errorFormatter->fromThrowable($exception),
                [],
                now(),
            );
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
            'post_type' => SeoProjectTask::isNewArticleType($failedTask->type)
                ? SeoProjectTask::normalizePostType($failedTask->post_type)
                : null,
            'loai_san_pham' => $failedTask->loai_san_pham,
            'source_content' => $failedTask->source_content,
            'description' => $failedTask->description,
            'target_date' => $failedTask->target_date,
            'article_id' => $failedTask->type === SeoProjectTask::TYPE_REWRITE
                ? $failedTask->article_id
                : null,
            'rewrite_mode' => $failedTask->type === SeoProjectTask::TYPE_REWRITE
                ? SeoProjectTask::normalizeRewriteMode($failedTask->rewrite_mode)
                : SeoProjectTask::REWRITE_MODE_KEYWORD,
            'rewrite_notes' => $failedTask->type === SeoProjectTask::TYPE_REWRITE
                ? $failedTask->rewrite_notes
                : null,
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);

        return (int) $retryTask->id;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createRetryTaskFromItem(SeoProject $project, array $item): SeoProjectTask
    {
        $type = (string) ($item['type'] ?? SeoProjectTask::TYPE_NEW_KEYWORD);
        $articleId = (int) ($item['article_id'] ?? 0);

        return $project->tasks()->create([
            'site_id' => (int) $project->site_id,
            'type' => $type,
            'post_type' => SeoProjectTask::isNewArticleType($type)
                ? SeoProjectTask::normalizePostType($item['post_type'] ?? null)
                : null,
            'loai_san_pham' => trim((string) ($item['loai_san_pham'] ?? '')) ?: null,
            'source_content' => trim((string) ($item['source_content'] ?? '')),
            'description' => trim((string) ($item['gallery_description'] ?? $item['description'] ?? '')) ?: null,
            'target_date' => $item['target_date'] ?? null,
            'article_id' => $articleId > 0 ? $articleId : null,
            'rewrite_mode' => $type === SeoProjectTask::TYPE_REWRITE
                ? SeoProjectTask::normalizeRewriteMode($item['rewrite_mode'] ?? null)
                : SeoProjectTask::REWRITE_MODE_KEYWORD,
            'rewrite_notes' => $type === SeoProjectTask::TYPE_REWRITE
                ? (trim((string) ($item['rewrite_notes'] ?? '')) ?: null)
                : null,
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

        if (SeoProjectTask::isNewArticleType($type)) {
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
        return $this->persistRunItems($run, $items, true);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function persistRunItems(SeoProjectRun $run, array $items, bool $markCompleted): SeoProjectRun
    {
        $succeeded = collect($items)->where('status', 'success')->count();
        $failed = collect($items)->where('status', 'failed')->count();

        $payload = [
            'succeeded' => $succeeded,
            'failed' => $failed,
            'items' => $items,
        ];

        if ($markCompleted || (int) $run->total <= 0) {
            $payload['total'] = max((int) $run->total, count($items));
        }

        if ($markCompleted) {
            $payload['status'] = SeoProjectRun::STATUS_COMPLETED;
            $payload['finished_at'] = now();
        } else {
            $payload['status'] = SeoProjectRun::STATUS_RUNNING;
            $payload['finished_at'] = null;
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($run, $payload): SeoProjectRun {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProjectRun) {
                $run->update($payload);

                return $run->fresh(['project']) ?? $run;
            }

            // Merge theo task_id với bản DB mới nhất trong lock — không để request cũ ghi đè mất hàng.
            $dbItems = is_array($locked->items) ? $locked->items : [];
            $incoming = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $payload['items'] = $this->mergeRunItemsByTaskId($dbItems, $incoming);
            $payload['succeeded'] = collect($payload['items'])->where('status', 'success')->count();
            $payload['failed'] = collect($payload['items'])->where('status', 'failed')->count();

            if (array_key_exists('total', $payload)) {
                $payload['total'] = max((int) $locked->total, (int) $payload['total'], count($payload['items']));
            }

            $locked->update($payload);

            return $locked->fresh(['project']) ?? $locked;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $dbItems
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeRunItemsByTaskId(array $dbItems, array $incoming): array
    {
        /** @var array<int, array<string, mixed>> $byTaskId */
        $byTaskId = [];
        /** @var list<int|array<string, mixed>> $order */
        $order = [];
        /** @var array<int, true> $seenTaskIds */
        $seenTaskIds = [];

        foreach (array_merge($dbItems, $incoming) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $taskId = (int) ($item['task_id'] ?? 0);
            if ($taskId <= 0) {
                $order[] = $item;
                continue;
            }

            if (! isset($seenTaskIds[$taskId])) {
                $seenTaskIds[$taskId] = true;
                $order[] = $taskId;
                $byTaskId[$taskId] = $item;
                continue;
            }

            $byTaskId[$taskId] = $this->preferRicherRunItem($byTaskId[$taskId], $item);
        }

        $merged = [];
        foreach ($order as $entry) {
            if (is_int($entry)) {
                $merged[] = $byTaskId[$entry];
                continue;
            }
            $merged[] = $entry;
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function preferRicherRunItem(array $a, array $b): array
    {
        $score = static function (array $item): int {
            return match ((string) ($item['status'] ?? '')) {
                'success' => 300,
                'failed' => 200,
                'manual' => 100,
                'pending' => 50,
                default => 0,
            };
        };

        if ($score($b) !== $score($a)) {
            return $score($b) > $score($a) ? $b : $a;
        }

        // Cùng rank: ưu tiên bản có article_id / message mới hơn.
        $aArticle = (int) ($a['article_id'] ?? 0);
        $bArticle = (int) ($b['article_id'] ?? 0);
        if ($bArticle > 0 && $aArticle <= 0) {
            return $b;
        }
        if ($aArticle > 0 && $bArticle <= 0) {
            return $a;
        }

        return $b;
    }

    /**
     * @return null|callable(Builder): void
     */
    private function articleScopeForProject(int $projectSiteId): ?callable
    {
        return function (Builder $builder) use ($projectSiteId): void {
            if (SeoAccessControl::shouldScopeToAccountOwner()) {
                SeoAccessControl::applyAccessibleSiteScope($builder);
            }

            if ($projectSiteId > 0) {
                $builder->where('site_id', $projectSiteId);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildImproveManualItemRow(SeoProjectTask $task, int $projectSiteId): array
    {
        $taskSiteId = (int) ($task->site_id ?? $projectSiteId);
        $articleId = $this->resolveArticleIdForTask($task, $taskSiteId > 0 ? $taskSiteId : $projectSiteId);
        $message = $articleId > 0
            ? __('seo-content-ai::filament.projects.run_item_manual_hint')
            : __('seo-content-ai::filament.projects.run_item_manual_no_article');

        return [
            'task_id' => (int) $task->id,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'source_content' => (string) $task->source_content,
            'post_type' => null,
            'loai_san_pham' => null,
            'gallery_description' => null,
            'target_date' => $task->target_date?->format('Y-m-d'),
            'status' => 'manual',
            'article_id' => $articleId > 0 ? $articleId : null,
            'article_edit_url' => $articleId > 0
                ? ArticleResource::getUrl('edit', ['record' => $articleId], isAbsolute: false)
                : null,
            'message' => $message,
            'steps' => [],
        ];
    }

    private function resolveArticleIdForTask(SeoProjectTask $task, int $siteId): int
    {
        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId > 0) {
            return $articleId;
        }

        $title = trim((string) $task->source_content);
        if ($title === '') {
            return 0;
        }

        $scope = $this->articleScopeForProject($siteId);
        $query = SeoArticle::query();
        if (is_callable($scope)) {
            $scope($query);
        }

        $resolved = (int) ($query
            ->where('title', $title)
            ->orderByDesc('id')
            ->value('id') ?? 0);

        return $resolved > 0 ? $resolved : 0;
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
        mixed $ranAt = null,
    ): array {
        $lastRunAt = $ranAt instanceof \DateTimeInterface
            ? $ranAt->format('Y-m-d H:i:s')
            : (is_string($ranAt) && trim($ranAt) !== '' ? trim($ranAt) : now()->format('Y-m-d H:i:s'));

        return [
            'task_id' => (int) $task->id,
            'type' => (string) $task->type,
            'source_content' => (string) $task->source_content,
            'post_type' => SeoProjectTask::isNewArticleType($task->type)
                ? SeoProjectTask::normalizePostType($task->post_type)
                : null,
            'loai_san_pham' => SeoProjectTask::isNewArticleType($task->type)
                && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                    ? (string) ($task->loai_san_pham ?? '')
                    : null,
            'gallery_description' => SeoProjectTask::isNewArticleType($task->type)
                && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                    ? (string) ($task->description ?? '')
                    : null,
            'target_date' => $task->target_date?->format('Y-m-d'),
            'last_run_at' => $lastRunAt,
            'status' => $success ? 'success' : 'failed',
            'article_id' => $articleId,
            'article_edit_url' => $articleId > 0 && $this->editorReadiness->isReady($articleId)
                ? ArticleResource::getUrl('edit', ['record' => $articleId], isAbsolute: false)
                : null,
            'article_editor_ready' => $articleId > 0 ? $this->editorReadiness->isReady($articleId) : true,
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
        mixed $ranAt = null,
    ): array {
        $row = $this->buildItemRow($task, false, $articleId, $error['message'], $steps, $ranAt);

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
     * @param  list<array<string, mixed>>  $steps
     * @return array{completed: int, skipped: int, failed: int, total: int}
     */
    private function summarizeStepStats(array $steps): array
    {
        $completed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $status = (string) ($step['status'] ?? '');
            match ($status) {
                'completed' => $completed++,
                'skipped' => $skipped++,
                'failed' => $failed++,
                default => null,
            };
        }

        return [
            'completed' => $completed,
            'skipped' => $skipped,
            'failed' => $failed,
            'total' => $completed + $skipped + $failed,
        ];
    }

    /**
     * @param  array{completed: int, skipped: int, failed: int, total: int}  $stepStats
     */
    private function formatRunResultMessage(string $baseMessage, \DateTimeInterface $ranAt, array $stepStats): string
    {
        $base = trim($baseMessage);
        if ($base === '') {
            $base = 'Đã chạy quy trình và tạo/cập nhật bài.';
        }

        return sprintf(
            '%s · Chạy lúc %s · AI xong %d / bỏ qua %d / lỗi %d.',
            $base,
            $ranAt->format('d/m/Y H:i:s'),
            (int) ($stepStats['completed'] ?? 0),
            (int) ($stepStats['skipped'] ?? 0),
            (int) ($stepStats['failed'] ?? 0),
        );
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

    private function markTaskWriting(SeoProjectTask $task): void
    {
        $existingArticleId = (int) ($task->article_id ?? 0);

        $this->persistTaskState(
            $task,
            SeoProjectTask::STATUS_WRITING,
            $existingArticleId > 0 ? $existingArticleId : null,
        );
    }

    private function markTaskFailed(SeoProjectTask $task, ?int $articleId = null): void
    {
        $resolvedArticleId = $articleId !== null && $articleId > 0
            ? $articleId
            : (((int) ($task->article_id ?? 0) > 0) ? (int) $task->article_id : null);

        $this->persistTaskState($task, SeoProjectTask::STATUS_FAILED, $resolvedArticleId);
    }

    private function markTaskCompleted(SeoProjectTask $task, int $articleId): void
    {
        if ($articleId > 0) {
            $this->releaseArticleLinkFromOtherTasks($articleId, (int) $task->id);
        }

        $this->persistTaskState(
            $task,
            SeoProjectTask::STATUS_COMPLETED,
            $articleId > 0 ? $articleId : null,
        );

        if ($articleId <= 0) {
            return;
        }

        $task->loadMissing('project');
        if ($task->project instanceof SeoProject) {
            $this->articleOwnerSync->assignWriterToArticle($task->project, $articleId);
        }
    }

    private function persistTaskState(SeoProjectTask $task, string $status, ?int $articleId): void
    {
        $payload = [
            'status' => $status,
            'article_id' => $articleId,
        ];

        if ($articleId !== null && $articleId > 0 && $task->connected_at === null) {
            $payload['connected_at'] = now();
        }

        if ($status === SeoProjectTask::STATUS_COMPLETED && $task->completed_at === null) {
            $payload['completed_at'] = now();
        }

        SeoProjectTask::query()->whereKey($task->id)->update($payload);

        $task->refresh();
    }

    private function releaseArticleLinkFromOtherTasks(int $articleId, int $keepTaskId): void
    {
        if ($articleId <= 0) {
            return;
        }

        SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->whereKeyNot($keepTaskId)
            ->update(['article_id' => null]);
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

        if (
            SeoProjectTask::isNewArticleType($task->type)
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
            && filled($task->description)
        ) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'gallery_description'],
                ['meta_value' => (string) $task->description],
            );
        }

        if (
            SeoProjectTask::isNewArticleType($task->type)
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
            && filled($task->loai_san_pham)
        ) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'loai_san_pham'],
                ['meta_value' => (string) $task->loai_san_pham],
            );
        }
    }
}
