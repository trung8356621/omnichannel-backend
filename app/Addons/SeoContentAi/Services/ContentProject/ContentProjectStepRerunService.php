<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Enums\ContentProjectStepRerunMode;
use App\Addons\SeoContentAi\Enums\SeoProjectRunItemStatus;
use App\Addons\SeoContentAi\Enums\WorkflowExecutionRole;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectRunItem;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\CreateArticlesFromTaskService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepCatalogService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepRetryService;
use App\Addons\SeoContentAi\Services\TaskTestInputResolver;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStepDescriptor;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStepRerunRequest;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStepRerunResult;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rerun bằng cấu hình hiện tại — tạo run item mới (append-only), không mutate history cũ.
 * Khác Retry (SeoProjectWorkflowStepRetryService) giữ snapshot lần lỗi.
 */
final class ContentProjectStepRerunService
{
    public function __construct(
        private readonly SeoProjectWorkflowStepCatalogService $catalog,
        private readonly ContentProjectStepSourceValidator $sourceValidator,
        private readonly SeoProjectWorkflowStepRetryService $stepExecutor,
        private readonly TaskTestInputResolver $inputResolver,
        private readonly CreateArticlesFromTaskService $createArticles,
        private readonly ContentProjectActiveExecutionResolver $activeResolver,
        private readonly ContentProjectExecutionFinalizer $finalizer,
    ) {}

    public function rerun(
        SeoProjectRun $run,
        SeoProject $project,
        ContentProjectStepRerunRequest $request,
    ): ContentProjectStepRerunResult {
        if ((int) $run->id !== $request->projectRunId) {
            return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_INVALID, 'Run id không khớp.');
        }

        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereKey($request->projectTaskId)
            ->first();

        if (! $task instanceof SeoProjectTask) {
            return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_INVALID, 'Hạng mục không thuộc project.');
        }

        $active = $this->activeResolver->findActiveForTask($run, (int) $task->id);
        if ($active !== null) {
            // Heal stale trước guard (ngưỡng chính thức staleMinutes) — không clear mù.
            $this->stepExecutor->abandonStaleActiveSteps((int) $run->id, (int) $task->id);
            $active = $this->activeResolver->findActiveForTask($run, (int) $task->id);
        }
        if ($active !== null) {
            $this->logRerunBlocked($run, $task, $active, $request);

            return $this->fail(
                $run,
                $request,
                ContentProjectStepRerunResult::STATUS_BLOCKED,
                'Bài đang có execution active — không tạo rerun song song.',
                (int) $task->id,
                (int) ($task->article_id ?? 0) ?: null,
            );
        }

        $descriptor = $this->catalog->findDescriptor($task, $request->targetNodeId);
        if (! $descriptor instanceof ContentProjectStepDescriptor) {
            return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_INVALID, 'Không tìm thấy node trong workflow hiện tại.');
        }

        if (! $descriptor->rerunnable) {
            return $this->fail(
                $run,
                $request,
                ContentProjectStepRerunResult::STATUS_INVALID,
                $descriptor->unavailableReason ?? 'Node không rerunnable.',
                (int) $task->id,
                (int) ($task->article_id ?? 0) ?: null,
            );
        }

        if ($request->targetExecutionRole !== null
            && $descriptor->executionRole !== null
            && $request->targetExecutionRole !== $descriptor->executionRole
        ) {
            return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_INVALID, 'execution_role không khớp node.');
        }

        $stale = $this->assertArticleNotStale($task, $request->expectedArticleUpdatedAt);
        if ($stale !== null) {
            return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_BLOCKED, $stale, (int) $task->id, (int) ($task->article_id ?? 0) ?: null);
        }

        $sourceError = $this->sourceValidator->validate($task, $descriptor);
        if ($sourceError !== null) {
            return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_INVALID, $sourceError, (int) $task->id, (int) ($task->article_id ?? 0) ?: null);
        }

        $nodeIds = $this->resolveNodePlan($task, $descriptor, $request->mode);
        if ($nodeIds === []) {
            return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_INVALID, 'Không có node để chạy.');
        }

        // Outline + Article explicit path (handoff artifact).
        if ($request->mode === ContentProjectStepRerunMode::StepAndDownstream
            && $descriptor->kind === 'outline'
            && $this->planIncludesContent($task, $nodeIds)
        ) {
            return $this->rerunOutlineThenArticle($run, $project, $task, $request, $descriptor, $nodeIds);
        }

        try {
            $createdItems = [];
            DB::connection('omi_seo_ai')->transaction(function () use ($run, $task, $descriptor, $request, $nodeIds, &$createdItems): void {
                // Race: lock ownership task row rồi recheck active trước khi append.
                SeoProjectTask::query()
                    ->whereKey((int) $task->id)
                    ->lockForUpdate()
                    ->first();

                $raceActive = $this->activeResolver->findActiveForTask($run, (int) $task->id);
                if ($raceActive !== null) {
                    $this->logRerunBlocked($run, $task, $raceActive, $request);
                    throw new \RuntimeException('Bài đang có execution active — không tạo rerun song song.');
                }

                foreach ($nodeIds as $nodeId) {
                    $step = $nodeId === $descriptor->nodeId
                        ? $descriptor
                        : $this->catalog->findDescriptor($task, $nodeId);
                    if (! $step instanceof ContentProjectStepDescriptor) {
                        continue;
                    }
                    $createdItems[] = [
                        'node_id' => $nodeId,
                        'item' => $this->createAppendOnlyItem($run, $task, $step, $request),
                    ];
                }
            });

            if ($createdItems === []) {
                return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_FAILED, 'Không tạo được execution item.');
            }

            $lastResult = null;
            $lastItemId = null;
            $failed = false;
            foreach ($createdItems as $index => $entry) {
                $item = $entry['item'];
                $nodeId = (string) $entry['node_id'];
                $lastItemId = (int) $item->id;
                $lastResult = $this->stepExecutor->executePreparedStepItem(
                    $run,
                    (int) $task->id,
                    $nodeId,
                    (int) $item->id,
                );
                if (($lastResult['status'] ?? '') !== 'success') {
                    $failed = true;
                    $this->finalizeLeftoverPending(
                        array_slice($createdItems, $index + 1),
                        'Blocked by upstream step failure — không chạy song song.',
                    );
                    break;
                }
            }

            $ok = ! $failed && is_array($lastResult) && ($lastResult['status'] ?? '') === 'success';

            return new ContentProjectStepRerunResult(
                success: $ok,
                status: $ok ? ContentProjectStepRerunResult::STATUS_SUCCESS : ContentProjectStepRerunResult::STATUS_FAILED,
                message: (string) ($lastResult['message'] ?? ($ok ? 'Đã chạy lại bước.' : 'Rerun thất bại.')),
                runId: (int) $run->id,
                taskId: (int) $task->id,
                targetNodeId: $request->targetNodeId,
                targetExecutionRole: $descriptor->executionRole ?? $request->targetExecutionRole,
                rerunMode: $request->mode->value,
                executionType: 'rerun',
                runItemId: $lastItemId,
                sourceRunItemId: $this->latestSourceStepItemId($run, (int) $task->id, $request->targetNodeId),
                articleId: (int) ($task->article_id ?? 0) ?: null,
                nodeIds: $nodeIds,
            );
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'execution active')) {
                return $this->fail(
                    $run,
                    $request,
                    ContentProjectStepRerunResult::STATUS_BLOCKED,
                    $exception->getMessage(),
                    (int) $task->id,
                    (int) ($task->article_id ?? 0) ?: null,
                );
            }

            RuntimeLogger::report($exception, [
                'endpoint' => 'seo.content_project.step_rerun',
                'run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
                'node_id' => $request->targetNodeId,
            ]);

            return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_FAILED, $exception->getMessage(), (int) $task->id, (int) ($task->article_id ?? 0) ?: null);
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'seo.content_project.step_rerun',
                'run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
                'node_id' => $request->targetNodeId,
            ]);

            return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_FAILED, $exception->getMessage(), (int) $task->id, (int) ($task->article_id ?? 0) ?: null);
        }
    }

    /**
     * Preview availability cho một task/node (tooltip / bulk).
     *
     * @return array{ok: bool, reason: ?string, descriptor: ?array<string, mixed>}
     */
    public function previewAvailability(
        SeoProjectRun $run,
        SeoProject $project,
        int $taskId,
        string $nodeId,
    ): array {
        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereKey($taskId)
            ->first();

        if (! $task instanceof SeoProjectTask) {
            return ['ok' => false, 'reason' => 'Hạng mục không thuộc project.', 'descriptor' => null];
        }

        if ($this->activeResolver->hasActiveForTask($run, $taskId)) {
            $this->stepExecutor->abandonStaleActiveSteps((int) $run->id, $taskId);
        }
        if ($this->activeResolver->hasActiveForTask($run, $taskId)) {
            $active = $this->activeResolver->findActiveForTask($run, $taskId);
            if ($active !== null) {
                RuntimeLogger::info('content_project.rerun_blocked_active', array_merge($active->toArray(), [
                    'action' => 'preview_availability',
                    'node_id' => $nodeId,
                ]));
            }

            return ['ok' => false, 'reason' => 'Bài đang có execution active.', 'descriptor' => null];
        }

        $descriptor = $this->catalog->findDescriptor($task, $nodeId);
        if (! $descriptor instanceof ContentProjectStepDescriptor || ! $descriptor->rerunnable) {
            return [
                'ok' => false,
                'reason' => $descriptor?->unavailableReason ?? 'Node không có trong workflow hiện tại.',
                'descriptor' => $descriptor?->toArray(),
            ];
        }

        $sourceError = $this->sourceValidator->validate($task, $descriptor);
        if ($sourceError !== null) {
            return ['ok' => false, 'reason' => $sourceError, 'descriptor' => $descriptor->toArray()];
        }

        return ['ok' => true, 'reason' => null, 'descriptor' => $descriptor->toArray()];
    }

    /**
     * @param  list<int|string>  $taskIds
     * @return array{
     *     selected_count: int,
     *     valid_count: int,
     *     invalid_count: int,
     *     valid: list<array{task_id: int, label: string}>,
     *     invalid: list<array{task_id: int, label: string, reason: string}>,
     *     can_execute: bool,
     *     message: string,
     *     target_node_id: string,
     *     label: string
     * }
     */
    public function previewBulk(
        SeoProjectRun $run,
        SeoProject $project,
        array $taskIds,
        string $nodeId,
        ?string $label = null,
    ): array {
        $taskIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));

        $valid = [];
        $invalid = [];
        $resolvedLabel = $label ?? 'Chạy lại bước';

        foreach ($taskIds as $taskId) {
            $task = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->whereKey($taskId)
                ->first();
            $rowLabel = $task instanceof SeoProjectTask
                ? trim((string) ($task->title ?? $task->keyword ?? ('#'.$taskId)))
                : '#'.$taskId;

            $check = $this->previewAvailability($run, $project, $taskId, $nodeId);
            if (($check['descriptor']['label'] ?? null) !== null) {
                $resolvedLabel = (string) $check['descriptor']['label'];
            }

            if ($check['ok']) {
                $valid[] = ['task_id' => $taskId, 'label' => $rowLabel !== '' ? $rowLabel : '#'.$taskId];
            } else {
                $invalid[] = [
                    'task_id' => $taskId,
                    'label' => $rowLabel !== '' ? $rowLabel : '#'.$taskId,
                    'reason' => (string) ($check['reason'] ?? 'Không hợp lệ'),
                ];
            }
        }

        $validCount = count($valid);
        $invalidCount = count($invalid);

        return [
            'selected_count' => $validCount + $invalidCount,
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'valid' => $valid,
            'invalid' => $invalid,
            'can_execute' => $validCount > 0,
            'message' => $validCount > 0
                ? sprintf('Hợp lệ: %d — Không hợp lệ: %d', $validCount, $invalidCount)
                : ($invalidCount > 0 ? 'Không có bài hợp lệ để chạy.' : 'Chưa chọn bài.'),
            'target_node_id' => $nodeId,
            'label' => $resolvedLabel,
        ];
    }

    /**
     * @param  list<int|string>  $taskIds
     * @return array{success: bool, message: string, created: int, skipped: int, failed: int, preview: array<string, mixed>, results: list<array<string, mixed>>}
     */
    public function executeBulkSerial(
        SeoProjectRun $run,
        SeoProject $project,
        array $taskIds,
        string $nodeId,
        ContentProjectStepRerunMode $mode = ContentProjectStepRerunMode::SingleStep,
        bool $allowPartial = false,
        ?int $requestedBy = null,
    ): array {
        $preview = $this->previewBulk($run, $project, $taskIds, $nodeId);
        if (! $preview['can_execute']) {
            return [
                'success' => false,
                'message' => (string) $preview['message'],
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'preview' => $preview,
                'results' => [],
            ];
        }

        if ($preview['invalid_count'] > 0 && ! $allowPartial) {
            return [
                'success' => false,
                'message' => 'Có bài không hợp lệ. Xác nhận chạy phần hợp lệ hoặc bỏ chọn bài lỗi.',
                'created' => 0,
                'skipped' => (int) $preview['invalid_count'],
                'failed' => 0,
                'preview' => $preview,
                'results' => [],
            ];
        }

        $created = 0;
        $failed = 0;
        $results = [];

        foreach ($preview['valid'] as $row) {
            $request = new ContentProjectStepRerunRequest(
                projectRunId: (int) $run->id,
                projectTaskId: (int) $row['task_id'],
                articleId: null,
                targetNodeId: $nodeId,
                targetExecutionRole: null,
                mode: $mode,
                requestedBy: $requestedBy,
            );
            $result = $this->rerun($run, $project, $request);
            $results[] = $result->toArray();
            if ($result->success) {
                $created++;
            } else {
                $failed++;
            }
        }

        return [
            'success' => $failed === 0 && $created > 0,
            'message' => $failed === 0
                ? "Đã rerun {$created} bài."
                : "Rerun xong: thành công {$created}, lỗi {$failed}.",
            'created' => $created,
            'skipped' => (int) $preview['invalid_count'],
            'failed' => $failed,
            'preview' => $preview,
            'results' => $results,
        ];
    }

    /**
     * @param  list<string>  $nodeIds
     */
    private function rerunOutlineThenArticle(
        SeoProjectRun $run,
        SeoProject $project,
        SeoProjectTask $task,
        ContentProjectStepRerunRequest $request,
        ContentProjectStepDescriptor $descriptor,
        array $nodeIds,
    ): ContentProjectStepRerunResult {
        try {
            $context = $this->inputResolver->resolveForProjectTask($task);
            $siteId = (int) ($project->site_id ?? 0);
            $result = $this->createArticles->runOutlineThenArticleForContext($context, $siteId);
            $ok = ($result['success'] ?? false) === true;

            // Audit trail: append-only marker item (không mutate step cũ).
            $marker = $this->createAppendOnlyItem($run, $task, $descriptor, $request, [
                'outline_then_article' => true,
                'handoff' => true,
                'runner_result' => [
                    'success' => $ok,
                    'message' => (string) ($result['message'] ?? ''),
                ],
            ]);
            $marker->fill([
                'status' => $ok
                    ? SeoProjectRunItemStatus::Success->value
                    : SeoProjectRunItemStatus::Failed->value,
                'finished_at' => now(),
                'message' => (string) ($result['message'] ?? ($ok ? 'Outline → bài xong.' : 'Outline → bài thất bại.')),
            ])->save();

            return new ContentProjectStepRerunResult(
                success: $ok,
                status: $ok ? ContentProjectStepRerunResult::STATUS_SUCCESS : ContentProjectStepRerunResult::STATUS_FAILED,
                message: (string) ($result['message'] ?? ($ok ? 'Đã tạo lại dàn ý và bài viết.' : 'Tạo lại dàn ý + bài thất bại.')),
                runId: (int) $run->id,
                taskId: (int) $task->id,
                targetNodeId: $request->targetNodeId,
                targetExecutionRole: $descriptor->executionRole,
                rerunMode: $request->mode->value,
                runItemId: (int) $marker->id,
                articleId: (int) ($task->article_id ?? 0) ?: null,
                nodeIds: $nodeIds,
            );
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'seo.content_project.step_rerun_outline_article',
                'run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
            ]);

            return $this->fail($run, $request, ContentProjectStepRerunResult::STATUS_FAILED, $exception->getMessage(), (int) $task->id, (int) ($task->article_id ?? 0) ?: null);
        }
    }

    /**
     * @param  array<string, mixed>  $extraSnapshot
     */
    private function createAppendOnlyItem(
        SeoProjectRun $run,
        SeoProjectTask $task,
        ContentProjectStepDescriptor $descriptor,
        ContentProjectStepRerunRequest $request,
        array $extraSnapshot = [],
    ): SeoProjectRunItem {
        $action = $this->rerunActionKey($descriptor->nodeId);
        $sourceItemId = $this->latestSourceStepItemId($run, (int) $task->id, $descriptor->nodeId);

        return SeoProjectRunItem::query()->create([
            'run_id' => (int) $run->id,
            'task_id' => (int) $task->id,
            'article_id' => (int) ($task->article_id ?? 0) ?: null,
            'action' => $action,
            'status' => SeoProjectRunItemStatus::Pending->value,
            'attempt' => 1,
            'idempotency_key' => hash('sha256', implode('|', [
                (int) $run->id,
                (int) $task->id,
                $action,
                (string) Str::uuid(),
            ])),
            'message' => 'Đang chờ rerun: '.$descriptor->label,
            'error_code' => null,
            'error_message' => null,
            'input_snapshot' => array_merge([
                'execution_type' => 'rerun',
                'rerun_mode' => $request->mode->value,
                'node_id' => $descriptor->nodeId,
                'target_node_id' => $descriptor->nodeId,
                'target_execution_role' => $descriptor->executionRole,
                'step_kind' => $descriptor->kind,
                'step_label' => $descriptor->label,
                'prompt_id' => $descriptor->promptId,
                'hook_key' => $descriptor->hookKey,
                'source_run_id' => (int) $run->id,
                'source_run_item_id' => $sourceItemId,
                'source_article_id' => (int) ($task->article_id ?? 0) ?: null,
                'requested_by' => $request->requestedBy,
                'uses_current_workflow' => true,
            ], $extraSnapshot),
            'output_snapshot' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    private function rerunActionKey(string $nodeId): string
    {
        // Giữ prefix step: để classifier / busy query hiện có vẫn nhận.
        $token = substr(str_replace('-', '', (string) Str::ulid()), 0, 12);
        $raw = 'step:rr:'.$token;
        if (strlen($raw) <= 64) {
            return $raw;
        }

        return 'step:rr:'.substr(hash('sha256', $nodeId.'|'.$token), 0, 40);
    }

    /**
     * @return list<string>
     */
    private function resolveNodePlan(
        SeoProjectTask $task,
        ContentProjectStepDescriptor $descriptor,
        ContentProjectStepRerunMode $mode,
    ): array {
        if ($mode === ContentProjectStepRerunMode::SingleStep) {
            return [$descriptor->nodeId];
        }

        $ids = [$descriptor->nodeId, ...$descriptor->downstreamNodeIds];

        return $this->catalog->orderNodeIdsByDependency($task, $ids);
    }

    /**
     * @param  list<string>  $nodeIds
     */
    private function planIncludesContent(SeoProjectTask $task, array $nodeIds): bool
    {
        foreach ($nodeIds as $nodeId) {
            $step = $this->catalog->findDescriptor($task, $nodeId);
            if ($step instanceof ContentProjectStepDescriptor && $step->kind === 'content') {
                return true;
            }
            if ($step?->executionRole === WorkflowExecutionRole::ArticleContentGenerate->value) {
                return true;
            }
        }

        return false;
    }

    private function taskHasActiveExecution(SeoProjectRun $run, int $taskId): bool
    {
        return $this->activeResolver->hasActiveForTask($run, $taskId);
    }

    /**
     * @param  list<array{node_id: string, item: SeoProjectRunItem}>  $leftovers
     */
    private function finalizeLeftoverPending(array $leftovers, string $reason): void
    {
        $items = [];
        foreach ($leftovers as $entry) {
            $item = $entry['item'] ?? null;
            if ($item instanceof SeoProjectRunItem) {
                $items[] = $item;
            }
        }
        if ($items === []) {
            return;
        }

        $this->finalizer->finalizeMany(
            $items,
            SeoProjectRunItemStatus::Skipped->value,
            $reason,
        );
    }

    private function logRerunBlocked(
        SeoProjectRun $run,
        SeoProjectTask $task,
        \App\Addons\SeoContentAi\Support\ContentProject\ContentProjectActiveExecution $active,
        ContentProjectStepRerunRequest $request,
    ): void {
        RuntimeLogger::info('content_project.rerun_blocked_active', array_merge($active->toArray(), [
            'run_id' => (int) $run->id,
            'article_id' => (int) ($task->article_id ?? 0) ?: null,
            'task_id' => (int) $task->id,
            'action' => $request->targetNodeId,
            'node_id' => $request->targetNodeId,
        ]));
    }

    private function latestSourceStepItemId(SeoProjectRun $run, int $taskId, string $nodeId): ?int
    {
        $legacyAction = $this->stepExecutor->stepAction($nodeId);
        $row = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->where('task_id', $taskId)
            ->where(function ($query) use ($legacyAction, $nodeId): void {
                $query->where('action', $legacyAction)
                    ->orWhere('input_snapshot->node_id', $nodeId)
                    ->orWhere('input_snapshot->target_node_id', $nodeId);
            })
            ->where('action', 'like', 'step:%')
            ->whereNotIn('status', \App\Addons\SeoContentAi\Support\ContentProject\ContentProjectExecutionStatus::activeStatuses())
            ->orderByDesc('id')
            ->first(['id']);

        return $row instanceof SeoProjectRunItem ? (int) $row->id : null;
    }

    private function assertArticleNotStale(SeoProjectTask $task, ?string $expectedUpdatedAt): ?string
    {
        if ($expectedUpdatedAt === null || trim($expectedUpdatedAt) === '') {
            return null;
        }

        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId <= 0) {
            return null;
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return null;
        }

        // Prefer content-change timestamps; fallback last_manual/synced — không dùng updated_at status noise.
        $current = $article->last_manual_saved_at
            ?? $article->last_synced_at
            ?? null;
        if ($current === null) {
            return null;
        }

        try {
            $expected = \Illuminate\Support\Carbon::parse($expectedUpdatedAt);
        } catch (\Throwable) {
            return null;
        }

        if ($current->gt($expected->addSecond())) {
            return 'Bài đã thay đổi sau khi mở form — tải lại rồi rerun.';
        }

        return null;
    }

    private function fail(
        SeoProjectRun $run,
        ContentProjectStepRerunRequest $request,
        string $status,
        string $message,
        ?int $taskId = null,
        ?int $articleId = null,
    ): ContentProjectStepRerunResult {
        return new ContentProjectStepRerunResult(
            success: false,
            status: $status,
            message: $message,
            runId: (int) $run->id,
            taskId: $taskId ?? $request->projectTaskId,
            targetNodeId: $request->targetNodeId,
            targetExecutionRole: $request->targetExecutionRole,
            rerunMode: $request->mode->value,
            articleId: $articleId ?? $request->articleId,
            nodeIds: $request->targetNodeId !== '' ? [$request->targetNodeId] : [],
        );
    }
}
