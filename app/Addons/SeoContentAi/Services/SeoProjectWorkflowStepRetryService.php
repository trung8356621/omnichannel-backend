<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Enums\SeoProjectRunItemStatus;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectRunItem;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\SeoTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chạy lại từng prompt/node trong workflow — không chạy lại toàn pipeline.
 */
final class SeoProjectWorkflowStepRetryService
{
    private const ACTIVE_STATUSES = [
        SeoProjectRunItemStatus::Pending->value,
        SeoProjectRunItemStatus::Processing->value,
    ];

    public function __construct(
        private readonly SeoProjectWorkflowStepCatalogService $catalog,
        private readonly TaskTestInputResolver $inputResolver,
        private readonly TaskWorkflowTestRunner $workflowRunner,
        private readonly SeoProjectRunItemService $runItemService,
    ) {}

    /**
     * @return list<array{
     *     node_id: string,
     *     title: string,
     *     label: string,
     *     kind: string,
     *     prompt_id: int|null,
     *     status: string|null,
     *     last_finished_at: string|null,
     *     busy: bool,
     *     can_retry: bool
     * }>
     */
    public function stepsForTask(SeoProjectRun $run, SeoProjectTask $task): array
    {
        $catalog = $this->catalog->listRerunnableSteps($task);
        $activeByAction = $this->activeStepStatuses((int) $run->id, (int) $task->id);
        $latestByAction = $this->latestStepFinishes((int) $run->id, (int) $task->id);

        $rows = [];
        foreach ($catalog as $step) {
            $action = $this->stepAction($step['node_id']);
            $status = $activeByAction[$action] ?? ($latestByAction[$action]['status'] ?? null);
            $busy = isset($activeByAction[$action]);

            $rows[] = [
                'node_id' => $step['node_id'],
                'title' => $step['title'],
                'label' => $step['label'],
                'kind' => $step['kind'],
                'prompt_id' => $step['prompt_id'],
                'status' => $status,
                'last_finished_at' => $latestByAction[$action]['finished_at'] ?? null,
                'busy' => $busy,
                'can_retry' => ! $busy,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $taskIds
     * @param  list<string>  $nodeIds
     * @return array{
     *     created: int,
     *     skipped: int,
     *     failed: int,
     *     results: list<array<string, mixed>>,
     *     message: string
     * }
     */
    public function enqueueBulk(
        SeoProjectRun $run,
        SeoProject $project,
        array $taskIds,
        array $nodeIds,
        bool $executeImmediately = true,
    ): array {
        $taskIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));
        $nodeIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $nodeIds),
            static fn (string $id): bool => $id !== '',
        )));

        if ($taskIds === [] || $nodeIds === []) {
            return [
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'results' => [],
                'message' => 'Chưa chọn bài hoặc prompt.',
            ];
        }

        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereIn('id', $taskIds)
            ->get()
            ->keyBy(static fn (SeoProjectTask $task): int => (int) $task->id);

        foreach ($taskIds as $taskId) {
            if (! $tasks->has($taskId)) {
                throw new \InvalidArgumentException('Task #'.$taskId.' không thuộc project hiện tại.');
            }
        }

        $plan = [];
        foreach ($taskIds as $taskId) {
            /** @var SeoProjectTask $task */
            $task = $tasks->get($taskId);
            $orderedNodes = $this->catalog->orderNodeIdsByDependency($task, $nodeIds);
            foreach ($orderedNodes as $nodeId) {
                $step = $this->catalog->findStep($task, $nodeId);
                if ($step === null) {
                    throw new \InvalidArgumentException(
                        'Prompt «'.$nodeId.'» không thuộc cấu hình workflow của task #'.$taskId.'.'
                    );
                }
                $plan[] = [
                    'task' => $task,
                    'node_id' => $nodeId,
                    'step' => $step,
                ];
            }
        }

        $createdKeys = [];
        $skipped = 0;

        DB::connection('omi_seo_ai')->transaction(function () use ($run, $plan, &$createdKeys, &$skipped): void {
            foreach ($plan as $entry) {
                /** @var SeoProjectTask $task */
                $task = $entry['task'];
                $nodeId = (string) $entry['node_id'];
                $action = $this->stepAction($nodeId);

                $active = SeoProjectRunItem::query()
                    ->where('run_id', (int) $run->id)
                    ->where('task_id', (int) $task->id)
                    ->where('action', $action)
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->lockForUpdate()
                    ->first();

                if ($active instanceof SeoProjectRunItem) {
                    $skipped++;
                    continue;
                }

                $runItem = $this->prepareStepRunItem($run, $task, $nodeId, $entry['step']);
                $createdKeys[] = [
                    'run_item_id' => (int) $runItem->id,
                    'task_id' => (int) $task->id,
                    'node_id' => $nodeId,
                ];
            }
        });

        $results = [];
        $created = count($createdKeys);
        $failed = 0;

        if ($executeImmediately) {
            foreach ($createdKeys as $key) {
                $result = $this->executePreparedStep(
                    $run,
                    (int) $key['task_id'],
                    (string) $key['node_id'],
                    (int) $key['run_item_id'],
                );
                $results[] = $result;
                if (($result['status'] ?? '') === 'failed') {
                    $failed++;
                }
            }
        }

        $message = sprintf(
            'Đã tạo %d task. Bỏ qua %d task vì đang chờ hoặc đang chạy.',
            $created,
            $skipped,
        );
        if ($failed > 0) {
            $message .= ' Thất bại: '.$failed.'.';
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retryOne(
        SeoProjectRun $run,
        SeoProject $project,
        int $taskId,
        string $nodeId,
    ): array {
        $bulk = $this->enqueueBulk($run, $project, [$taskId], [$nodeId], executeImmediately: true);

        if ($bulk['skipped'] > 0 && $bulk['created'] === 0) {
            return [
                'success' => false,
                'status' => 'busy',
                'message' => 'Prompt của bài này đang chờ xử lý hoặc đang chạy.',
                'bulk' => $bulk,
            ];
        }

        $first = $bulk['results'][0] ?? null;
        if (! is_array($first)) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => $bulk['message'],
                'bulk' => $bulk,
            ];
        }

        return [
            'success' => ($first['status'] ?? '') === 'success',
            'status' => (string) ($first['status'] ?? 'failed'),
            'message' => (string) ($first['message'] ?? $bulk['message']),
            'item' => $first,
            'bulk' => $bulk,
        ];
    }

    /**
     * @param  array<string, mixed>  $stepMeta
     */
    private function prepareStepRunItem(
        SeoProjectRun $run,
        SeoProjectTask $task,
        string $nodeId,
        array $stepMeta,
    ): SeoProjectRunItem {
        $action = $this->stepAction($nodeId);
        $existing = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->where('task_id', (int) $task->id)
            ->where('action', $action)
            ->first();

        $attempt = $existing instanceof SeoProjectRunItem
            ? max(1, (int) $existing->attempt) + 1
            : 1;

        $payload = [
            'run_id' => (int) $run->id,
            'task_id' => (int) $task->id,
            'article_id' => (int) ($task->article_id ?? 0) ?: null,
            'action' => $action,
            'status' => SeoProjectRunItemStatus::Pending->value,
            'attempt' => $attempt,
            'idempotency_key' => hash('sha256', implode('|', [
                (int) $run->id,
                (int) $task->id,
                $action,
                'attempt:'.$attempt,
                (string) Str::uuid(),
            ])),
            'message' => 'Đang chờ chạy lại: '.(string) ($stepMeta['label'] ?? $nodeId),
            'error_code' => null,
            'error_message' => null,
            'input_snapshot' => [
                'node_id' => $nodeId,
                'step_kind' => $stepMeta['kind'] ?? null,
                'step_label' => $stepMeta['label'] ?? null,
                'prompt_id' => $stepMeta['prompt_id'] ?? null,
                'retry_mode' => 'workflow_step',
            ],
            'output_snapshot' => null,
            'started_at' => null,
            'finished_at' => null,
        ];

        if ($existing instanceof SeoProjectRunItem) {
            $existing->fill($payload);
            $existing->save();

            return $existing->fresh() ?? $existing;
        }

        return SeoProjectRunItem::query()->create($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function executePreparedStep(
        SeoProjectRun $run,
        int $taskId,
        string $nodeId,
        int $runItemId,
    ): array {
        $run->loadMissing('project.site');
        $project = $run->project;
        if (! $project instanceof SeoProject) {
            return $this->failPrepared($runItemId, 'Không tìm thấy dự án của lần run này.');
        }

        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereKey($taskId)
            ->first();

        if (! $task instanceof SeoProjectTask) {
            return $this->failPrepared($runItemId, 'Không tìm thấy hạng mục #'.$taskId.' trong dự án.');
        }

        $stepMeta = $this->catalog->findStep($task, $nodeId);
        if ($stepMeta === null) {
            return $this->failPrepared($runItemId, 'Prompt không thuộc cấu hình workflow.');
        }

        $seoTask = $this->catalog->resolveSeoTask($task);
        if (! $seoTask instanceof SeoTask) {
            return $this->failPrepared($runItemId, 'Chưa cấu hình quy trình đăng bài / viết lại.');
        }

        $runItem = SeoProjectRunItem::query()->find($runItemId);
        if (! $runItem instanceof SeoProjectRunItem) {
            return [
                'task_id' => $taskId,
                'node_id' => $nodeId,
                'status' => 'failed',
                'message' => 'Không tìm thấy run item.',
            ];
        }

        $dependencyError = $this->assertDependencies($task, $stepMeta);
        if ($dependencyError !== null) {
            return $this->failPrepared($runItemId, $dependencyError, $taskId, $nodeId);
        }

        $runItem->update([
            'status' => SeoProjectRunItemStatus::Processing->value,
            'started_at' => now(),
            'message' => 'Đang chạy: '.(string) $stepMeta['label'],
        ]);

        try {
            $projectSiteId = (int) ($project->site_id ?? 0);
            if ($projectSiteId <= 0) {
                return $this->failPrepared($runItemId, 'Thiếu site_id.', $taskId, $nodeId);
            }

            $context = $this->inputResolver->resolveForProjectTask(
                $task,
                function ($builder) use ($projectSiteId): void {
                    $builder->where('site_id', $projectSiteId);
                },
            );

            if (! $context->article instanceof SeoArticle && (int) ($task->article_id ?? 0) > 0) {
                $article = SeoArticle::query()->find((int) $task->article_id);
                if ($article instanceof SeoArticle) {
                    $context = $context->withArticle($article);
                }
            }

            if (! $context->article instanceof SeoArticle && in_array($stepMeta['kind'], ['content', 'image', 'faq', 'meta_title', 'meta_description', 'slug'], true)) {
                return $this->failPrepared(
                    $runItemId,
                    'Không thể chạy «'.$stepMeta['label'].'» vì bài này chưa có article.',
                    $taskId,
                    $nodeId,
                );
            }

            $priorSteps = $this->priorStepsForNode($run, $task, $nodeId, $seoTask);
            $stepResult = $this->workflowRunner->runSingleStep($seoTask, $context, $nodeId, $priorSteps);
            $status = (string) ($stepResult['status'] ?? '');

            if (in_array($status, ['failed', 'error'], true)) {
                return $this->failPrepared(
                    $runItemId,
                    (string) ($stepResult['message'] ?? 'Bước thất bại.'),
                    $taskId,
                    $nodeId,
                    $stepResult,
                );
            }

            $article = $context->article;
            if ($article instanceof SeoArticle) {
                $this->workflowRunner->applyParsedMetaFromSteps($article, [$stepResult]);
            }

            $runItem->refresh();
            $runItem->update([
                'status' => SeoProjectRunItemStatus::Success->value,
                'article_id' => $article instanceof SeoArticle ? (int) $article->id : $runItem->article_id,
                'message' => (string) ($stepResult['message'] ?? ('Đã chạy lại: '.$stepMeta['label'])),
                'error_code' => null,
                'error_message' => null,
                'output_snapshot' => [
                    'steps' => [$stepResult],
                    'node_id' => $nodeId,
                    'step_kind' => $stepMeta['kind'],
                    'step_label' => $stepMeta['label'],
                ],
                'finished_at' => now(),
            ]);

            $this->runItemService->syncMirrorAndCounters($run, false);

            return [
                'task_id' => $taskId,
                'node_id' => $nodeId,
                'run_item_id' => (int) $runItem->id,
                'status' => 'success',
                'message' => (string) ($runItem->message ?? ''),
                'step' => $stepResult,
                'article_id' => $article instanceof SeoArticle ? (int) $article->id : null,
            ];
        } catch (\Throwable $exception) {
            return $this->failPrepared(
                $runItemId,
                $exception->getMessage(),
                $taskId,
                $nodeId,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $stepMeta
     */
    private function assertDependencies(SeoProjectTask $task, array $stepMeta): ?string
    {
        $depends = $stepMeta['depends_on_kinds'] ?? [];
        if (! is_array($depends) || $depends === []) {
            return null;
        }

        $articleId = (int) ($task->article_id ?? 0);
        $article = $articleId > 0 ? SeoArticle::query()->find($articleId) : null;

        foreach ($depends as $kind) {
            if ($kind !== 'outline') {
                continue;
            }

            $hasOutline = false;
            if ($article instanceof SeoArticle) {
                $article->loadMissing('articleMetas');
                $outline = trim((string) (
                    $article->articleMetas->firstWhere('meta_key', 'seo_article_outline')?->meta_value ?? ''
                ));
                $hasOutline = $outline !== '';
            }

            if (! $hasOutline) {
                return 'Không thể tạo lại bài viết vì bài này chưa có outline.';
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function priorStepsForNode(
        SeoProjectRun $run,
        SeoProjectTask $task,
        string $nodeId,
        SeoTask $seoTask,
    ): array {
        $latest = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->where('task_id', (int) $task->id)
            ->where('status', SeoProjectRunItemStatus::Success->value)
            ->orderByDesc('id')
            ->get();

        foreach ($latest as $item) {
            $steps = is_array($item->output_snapshot['steps'] ?? null)
                ? $item->output_snapshot['steps']
                : [];
            if ($steps === []) {
                continue;
            }

            $prior = [];
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                if ((string) ($step['node_id'] ?? '') === $nodeId) {
                    break;
                }
                $prior[] = $step;
            }

            if ($prior !== []) {
                return $prior;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $stepResult
     * @return array<string, mixed>
     */
    private function failPrepared(
        int $runItemId,
        string $message,
        int $taskId = 0,
        string $nodeId = '',
        ?array $stepResult = null,
    ): array {
        $runItem = SeoProjectRunItem::query()->find($runItemId);
        if ($runItem instanceof SeoProjectRunItem) {
            $runItem->update([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'message' => $message,
                'error_message' => $message,
                'output_snapshot' => $stepResult !== null
                    ? ['steps' => [$stepResult], 'node_id' => $nodeId]
                    : $runItem->output_snapshot,
                'finished_at' => now(),
            ]);

            $run = SeoProjectRun::query()->find((int) $runItem->run_id);
            if ($run instanceof SeoProjectRun) {
                $this->runItemService->syncMirrorAndCounters($run, false);
            }
        }

        return [
            'task_id' => $taskId,
            'node_id' => $nodeId,
            'run_item_id' => $runItemId,
            'status' => 'failed',
            'message' => $message,
        ];
    }

    public function stepAction(string $nodeId): string
    {
        $raw = 'step:'.$nodeId;
        if (strlen($raw) <= 64) {
            return $raw;
        }

        return 'step:'.substr(hash('sha256', $nodeId), 0, 40);
    }

    /**
     * @return array<string, string>
     */
    private function activeStepStatuses(int $runId, int $taskId): array
    {
        $rows = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('task_id', $taskId)
            ->where('action', 'like', 'step:%')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->get(['action', 'status']);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->action] = (string) $row->status;
        }

        return $map;
    }

    /**
     * @return array<string, array{status: string, finished_at: string|null}>
     */
    private function latestStepFinishes(int $runId, int $taskId): array
    {
        $rows = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('task_id', $taskId)
            ->where('action', 'like', 'step:%')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->get(['action', 'status', 'finished_at']);

        $map = [];
        foreach ($rows as $row) {
            $action = (string) $row->action;
            if (isset($map[$action])) {
                continue;
            }
            $map[$action] = [
                'status' => (string) $row->status,
                'finished_at' => $row->finished_at?->timezone(config('app.timezone'))->format('H:i'),
            ];
        }

        return $map;
    }
}
