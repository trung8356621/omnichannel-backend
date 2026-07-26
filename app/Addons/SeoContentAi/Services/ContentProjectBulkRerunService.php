<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Enums\ContentProjectStepRerunMode;
use App\Addons\SeoContentAi\Enums\WorkflowExecutionRole;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectActiveExecutionResolver;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectStepRerunService;
use App\Addons\SeoContentAi\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use App\Support\RuntimeLogger;

/**
 * Bulk rerun theo execution_role — outline / article / outline+article.
 * Phase 2.0: ủy quyền ContentProjectStepRerunService (serial, append-only).
 */
final class ContentProjectBulkRerunService
{
    public const ACTION_OUTLINE = 'regenerate_outline';

    public const ACTION_ARTICLE = 'regenerate_article';

    public const ACTION_OUTLINE_AND_ARTICLE = 'regenerate_outline_and_article';

    public function __construct(
        private readonly WorkflowExecutionRoleResolver $roleResolver,
        private readonly SeoProjectWorkflowStepCatalogService $catalog,
        private readonly ContentProjectStepRerunService $stepRerun,
    ) {}

    /**
     * @param  list<int|string>  $taskIds
     * @return array{
     *     action: string,
     *     workflow_name: string,
     *     outline_node_title: ?string,
     *     article_node_title: ?string,
     *     outline_node_id: ?string,
     *     article_node_id: ?string,
     *     selected_count: int,
     *     valid_count: int,
     *     invalid_count: int,
     *     valid: list<array{task_id: int, label: string}>,
     *     invalid: list<array{task_id: int, label: string, reason: string}>,
     *     can_execute: bool,
     *     message: string
     * }
     */
    public function preview(
        SeoProjectRun $run,
        SeoProject $project,
        array $taskIds,
        string $action,
    ): array {
        $action = $this->normalizeAction($action);
        $classified = $this->classifyTasks($run, $project, $taskIds, $action);
        $meta = $classified['meta'];

        $validCount = count($classified['valid']);
        $invalidCount = count($classified['invalid']);
        $canExecute = $validCount > 0;

        $message = $canExecute
            ? sprintf('Hợp lệ: %d — Không hợp lệ: %d', $validCount, $invalidCount)
            : ($invalidCount > 0
                ? 'Không có bài hợp lệ để chạy.'
                : 'Chưa chọn bài.');

        return [
            'action' => $action,
            'workflow_name' => (string) ($meta['workflow_name'] ?? ''),
            'outline_node_title' => $meta['outline_node_title'] ?? null,
            'article_node_title' => $meta['article_node_title'] ?? null,
            'outline_node_id' => $meta['outline_node_id'] ?? null,
            'article_node_id' => $meta['article_node_id'] ?? null,
            'selected_count' => $validCount + $invalidCount,
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'valid' => $classified['valid'],
            'invalid' => $classified['invalid'],
            'can_execute' => $canExecute,
            'message' => $message,
        ];
    }

    /**
     * @param  list<int|string>  $taskIds
     * @return array{
     *     success: bool,
     *     message: string,
     *     created: int,
     *     skipped: int,
     *     failed: int,
     *     preview: array<string, mixed>
     * }
     */
    public function execute(
        SeoProjectRun $run,
        SeoProject $project,
        array $taskIds,
        string $action,
        bool $allowPartial = false,
    ): array {
        $action = $this->normalizeAction($action);
        $preview = $this->preview($run, $project, $taskIds, $action);

        if (! $preview['can_execute']) {
            return [
                'success' => false,
                'message' => (string) $preview['message'],
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'preview' => $preview,
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
            ];
        }

        $validIds = array_map(
            static fn (array $row): int => (int) $row['task_id'],
            $preview['valid'],
        );

        try {
            $mode = $action === self::ACTION_OUTLINE_AND_ARTICLE
                ? ContentProjectStepRerunMode::StepAndDownstream
                : ContentProjectStepRerunMode::SingleStep;

            $nodeId = $action === self::ACTION_ARTICLE
                ? (string) ($preview['article_node_id'] ?? '')
                : (string) ($preview['outline_node_id'] ?? '');

            if ($nodeId === '') {
                return [
                    'success' => false,
                    'message' => 'Workflow thiếu node role cần thiết.',
                    'created' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'preview' => $preview,
                ];
            }

            $bulk = $this->stepRerun->executeBulkSerial(
                $run,
                $project,
                $validIds,
                $nodeId,
                $mode,
                $allowPartial,
            );

            return [
                'success' => (bool) ($bulk['success'] ?? false),
                'message' => (string) ($bulk['message'] ?? ''),
                'created' => (int) ($bulk['created'] ?? 0),
                'skipped' => (int) ($bulk['skipped'] ?? 0),
                'failed' => (int) ($bulk['failed'] ?? 0),
                'preview' => $preview,
            ];
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'seo.project_run.bulk_rerun_by_action',
                'run_id' => (int) $run->id,
                'action' => $action,
            ]);

            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'created' => 0,
                'skipped' => 0,
                'failed' => count($validIds),
                'preview' => $preview,
            ];
        }
    }

    /**
     * @deprecated Phase 2.0 — outline+article đi qua StepRerunService StepAndDownstream.
     * @param  list<int>  $taskIds
     * @param  array<string, mixed>  $preview
     * @return array{success: bool, message: string, created: int, skipped: int, failed: int, preview: array<string, mixed>}
     */
    private function executeOutlineThenArticle(
        SeoProjectRun $run,
        SeoProject $project,
        array $taskIds,
        array $preview,
    ): array {
        $nodeId = (string) ($preview['outline_node_id'] ?? '');
        if ($nodeId === '') {
            return [
                'success' => false,
                'message' => 'Workflow thiếu node outline.',
                'created' => 0,
                'skipped' => 0,
                'failed' => count($taskIds),
                'preview' => $preview,
            ];
        }

        $bulk = $this->stepRerun->executeBulkSerial(
            $run,
            $project,
            $taskIds,
            $nodeId,
            ContentProjectStepRerunMode::StepAndDownstream,
            true,
        );

        return [
            'success' => (bool) ($bulk['success'] ?? false),
            'message' => (string) ($bulk['message'] ?? ''),
            'created' => (int) ($bulk['created'] ?? 0),
            'skipped' => (int) ($bulk['skipped'] ?? 0) + (int) ($preview['invalid_count'] ?? 0),
            'failed' => (int) ($bulk['failed'] ?? 0),
            'preview' => $preview,
        ];
    }

    /**
     * @param  list<int|string>  $taskIds
     * @return array{
     *     valid: list<array{task_id: int, label: string}>,
     *     invalid: list<array{task_id: int, label: string, reason: string}>,
     *     meta: array{
     *         workflow_name: string,
     *         outline_node_id: ?string,
     *         article_node_id: ?string,
     *         outline_node_title: ?string,
     *         article_node_title: ?string
     *     }
     * }
     */
    private function classifyTasks(
        SeoProjectRun $run,
        SeoProject $project,
        array $taskIds,
        string $action,
    ): array {
        $taskIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));

        $meta = [
            'workflow_name' => '',
            'outline_node_id' => null,
            'article_node_id' => null,
            'outline_node_title' => null,
            'article_node_title' => null,
        ];

        $valid = [];
        $invalid = [];

        if ($taskIds === []) {
            return compact('valid', 'invalid', 'meta');
        }

        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereIn('id', $taskIds)
            ->get()
            ->keyBy(static fn (SeoProjectTask $task): int => (int) $task->id);

        $probe = null;
        foreach ($tasks as $candidate) {
            if (! $candidate instanceof SeoProjectTask) {
                continue;
            }
            if (SeoProjectTask::isManualRunType((string) $candidate->type)) {
                continue;
            }
            if ($this->catalog->resolveSeoTaskForStepRetry($candidate) instanceof SeoTask) {
                $probe = $candidate;
                break;
            }
        }
        if ($probe instanceof SeoProjectTask) {
            $seoTask = $this->catalog->resolveSeoTaskForStepRetry($probe);
            if ($seoTask instanceof SeoTask) {
                $meta['workflow_name'] = trim((string) ($seoTask->name ?? ''));
                $outline = $this->roleResolver->findNode($seoTask, WorkflowExecutionRole::ArticleOutlineGenerate);
                $content = $this->roleResolver->findNode($seoTask, WorkflowExecutionRole::ArticleContentGenerate);
                $meta['outline_node_id'] = $outline['node_id'] ?? null;
                $meta['article_node_id'] = $content['node_id'] ?? null;
                $meta['outline_node_title'] = isset($outline['node']['title'])
                    ? trim((string) $outline['node']['title'])
                    : null;
                $meta['article_node_title'] = isset($content['node']['title'])
                    ? trim((string) $content['node']['title'])
                    : null;
            }
        }

        $needsOutline = in_array($action, [self::ACTION_OUTLINE, self::ACTION_OUTLINE_AND_ARTICLE], true);
        $needsContent = in_array($action, [self::ACTION_ARTICLE, self::ACTION_OUTLINE_AND_ARTICLE], true);
        $needsArticle = $action === self::ACTION_ARTICLE;

        foreach ($taskIds as $taskId) {
            /** @var SeoProjectTask|null $task */
            $task = $tasks->get($taskId);
            if (! $task instanceof SeoProjectTask) {
                $invalid[] = [
                    'task_id' => $taskId,
                    'label' => '#'.$taskId,
                    'reason' => 'Không thuộc project hiện tại.',
                ];

                continue;
            }

            $label = $this->taskLabel($task);

            if (SeoProjectTask::isManualRunType((string) $task->type)) {
                $invalid[] = [
                    'task_id' => $taskId,
                    'label' => $label,
                    'reason' => 'Loại hạng mục không hỗ trợ bulk rerun.',
                ];

                continue;
            }

            if ((string) ($task->status ?? '') === SeoProjectTask::STATUS_WRITING) {
                $invalid[] = [
                    'task_id' => $taskId,
                    'label' => $label,
                    'reason' => 'Đang viết — xung đột writing active.',
                ];

                continue;
            }

            $active = app(ContentProjectActiveExecutionResolver::class)
                ->findActiveForTask($run, $taskId);
            if ($active !== null) {
                $invalid[] = [
                    'task_id' => $taskId,
                    'label' => $label,
                    'reason' => 'Đang có step pending/processing trên run này.',
                ];

                continue;
            }

            $seoTask = $this->catalog->resolveSeoTaskForStepRetry($task);
            if (! $seoTask instanceof SeoTask) {
                $invalid[] = [
                    'task_id' => $taskId,
                    'label' => $label,
                    'reason' => 'Không resolve được workflow Publish.',
                ];

                continue;
            }

            if ($needsOutline && $this->roleResolver->findNode($seoTask, WorkflowExecutionRole::ArticleOutlineGenerate) === null) {
                $invalid[] = [
                    'task_id' => $taskId,
                    'label' => $label,
                    'reason' => 'Workflow thiếu role article.outline.generate.',
                ];

                continue;
            }

            if ($needsContent && $this->roleResolver->findNode($seoTask, WorkflowExecutionRole::ArticleContentGenerate) === null) {
                $invalid[] = [
                    'task_id' => $taskId,
                    'label' => $label,
                    'reason' => 'Workflow thiếu role article.content.generate.',
                ];

                continue;
            }

            if ($needsArticle && (int) ($task->article_id ?? 0) <= 0) {
                $invalid[] = [
                    'task_id' => $taskId,
                    'label' => $label,
                    'reason' => 'Thiếu bài viết gắn với hạng mục.',
                ];

                continue;
            }

            $valid[] = [
                'task_id' => $taskId,
                'label' => $label,
            ];
        }

        return compact('valid', 'invalid', 'meta');
    }

    private function taskLabel(SeoProjectTask $task): string
    {
        $keyword = trim((string) ($task->keyword ?? ''));
        $title = trim((string) ($task->title ?? ''));
        $base = $keyword !== '' ? $keyword : ($title !== '' ? $title : 'Task');

        return $base.' (#'.(int) $task->id.')';
    }

    private function normalizeAction(string $action): string
    {
        $action = trim($action);

        return match ($action) {
            self::ACTION_OUTLINE,
            self::ACTION_ARTICLE,
            self::ACTION_OUTLINE_AND_ARTICLE => $action,
            default => throw new \InvalidArgumentException('Action bulk rerun không hợp lệ: '.$action),
        };
    }
}
