<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Enums\ArticleReviewActionType;
use App\Addons\SeoContentAi\Enums\ArticleReviewStatus;
use App\Addons\SeoContentAi\Enums\WorkflowExecutionRole;
use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectRunItem;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ArticleEditorReadinessService;
use App\Addons\SeoContentAi\Services\ArticleLastContentChangeResolver;
use App\Addons\SeoContentAi\Services\ArticleLastSavedTimestampService;
use App\Addons\SeoContentAi\Services\ArticleReviewService;
use App\Addons\SeoContentAi\Services\ContentProjectArticleRowStatusResolver;
use App\Addons\SeoContentAi\Services\ContentProjectBulkRerunService;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectStepRerunService;
use App\Addons\SeoContentAi\Enums\ContentProjectStepRerunMode;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStepRerunRequest;
use App\Addons\SeoContentAi\Services\Exceptions\ArticleReviewException;
use App\Addons\SeoContentAi\Services\SeoProjectRunItemService;
use App\Addons\SeoContentAi\Services\SeoProjectRunItemsReader;
use App\Addons\SeoContentAi\Services\SeoProjectTaskLifecycleService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepCatalogService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepRetryService;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectRunBulkSyncService;
use App\Addons\SeoContentAi\Services\RunEngine\ContentProjectRunEngine;
use App\Addons\SeoContentAi\Support\ContentProjectRunSettings;
use App\Addons\SeoContentAi\Support\RunEngine\ContentProjectRunEngineFeature;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SeoProjectRunErrorFormatter;
use App\Addons\SeoContentAi\Support\SeoProjectRunItemsDisplayPresenter;
use App\Models\User;
use App\Support\RuntimeLogger;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Throwable;

class ViewSeoProjectRun extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.view-project-run';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static bool $shouldRegisterNavigation = false;

    /** Route parameter `{run}` — scalar only; model is loaded in mount(). */
    public int|string $run;

    public ?SeoProjectRun $projectRun = null;

    public function mount(int|string $run): void
    {
        static::authorizeResourceAccess();

        $this->run = (int) $run;
        $this->projectRun = SeoProjectRun::query()
            ->with(['project.site', 'user', 'project.tasks'])
            ->findOrFail($this->run);

        abort_unless(
            SeoAccessControl::canAccessContentProjectRun($this->projectRun->project),
            403,
        );

        $runner = app(SeoProjectWorkflowRunService::class);
        $runner->ensureFailedTasksQueued($this->projectRun);
        $this->projectRun = $runner->reconcileMissingCompletedItems($this->projectRun);
        $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);
    }

    public function getTitle(): string|Htmlable
    {
        $projectName = (string) ($this->projectRun?->project?->name ?? '');

        return __('seo-content-ai::filament.projects.run_results_title', [
            'project' => $projectName,
            'id' => (int) ($this->projectRun?->id ?? 0),
        ]);
    }

    public function getHeading(): string|Htmlable
    {
        return new HtmlString(
            view('seo-content-ai::filament.resources.seo-project-resource.pages.partials.run-queue-heading', [
                'title' => $this->getTitle(),
            ])->render(),
        );
    }

    /**
     * @deprecated Entry «Chạy lại toàn bộ» đã gỡ — giữ method rỗng để compat bootstrap cũ.
     *
     * @return list<int>
     */
    public function getRerunAllTaskIds(): array
    {
        return [];
    }

    public function canRerunAllItems(): bool
    {
        return false;
    }

    /**
     * Prompt/node có thể chạy lại (bulk + per-row menu).
     *
     * @return list<array{node_id: string, label: string, kind: string, title: string}>
     */
    public function getBulkWorkflowSteps(): array
    {
        if ($this->projectRun?->project === null) {
            return [];
        }

        $catalog = app(SeoProjectWorkflowStepCatalogService::class);
        $byNode = [];

        foreach ($this->projectRun->project->tasks ?? [] as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            if (SeoProjectTask::isManualRunType((string) $task->type)) {
                continue;
            }

            foreach ($catalog->listRerunnableSteps($task) as $step) {
                $byNode[$step['node_id']] = [
                    'node_id' => $step['node_id'],
                    'label' => $step['label'],
                    'kind' => $step['kind'],
                    'title' => $step['title'],
                ];
            }
        }

        return array_values($byNode);
    }

    public function canRetryWorkflowSteps(): bool
    {
        return $this->projectRun !== null
            && SeoAccessControl::canRetryProjectRunItem($this->projectRun->project);
    }

    /**
     * @return array{success: bool, message: string, item?: array<string, mixed>}
     */
    public function retryWorkflowStep(int $taskId, string $nodeId): array
    {
        if ($this->projectRun === null) {
            $this->skipRender();

            return ['success' => false, 'message' => 'Run không tồn tại.'];
        }

        if ($this->shouldBlockPhpEngineArticleMutation('retryWorkflowStep')) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => 'PHP engine đang chạy — không rerun step khi run còn active.',
            ];
        }

        abort_unless(
            SeoAccessControl::canRetryProjectRunItem($this->projectRun->project),
            403,
        );

        try {
            $request = new ContentProjectStepRerunRequest(
                projectRunId: (int) $this->projectRun->id,
                projectTaskId: $taskId,
                articleId: null,
                targetNodeId: $nodeId,
                targetExecutionRole: null,
                mode: ContentProjectStepRerunMode::SingleStep,
                requestedBy: auth()->id() !== null ? (int) auth()->id() : null,
            );
            $result = app(ContentProjectStepRerunService::class)->rerun(
                $this->projectRun,
                $this->projectRun->project,
                $request,
            );
            $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);
            $this->skipRender();

            return [
                'success' => $result->success,
                'message' => $result->message,
                'item' => $result->toArray(),
            ];
        } catch (\Throwable $exception) {
            try {
                app(SeoProjectWorkflowStepRetryService::class)->cancelActiveStep(
                    $this->projectRun,
                    $taskId,
                    $nodeId,
                );
            } catch (\Throwable) {
                // Best-effort clear busy flag.
            }
            RuntimeLogger::report($exception, [
                'endpoint' => 'seo.project_run.retry_workflow_step',
                'run_id' => (int) $this->projectRun->id,
                'task_id' => $taskId,
                'node_id' => $nodeId,
            ]);
            $this->skipRender();

            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, message: string, preview?: array<string, mixed>}
     */
    public function previewBulkGenericStep(array $taskIds, string $nodeId): array
    {
        if ($this->projectRun === null) {
            $this->skipRender();

            return ['success' => false, 'message' => 'Run không tồn tại.'];
        }

        abort_unless(
            SeoAccessControl::canRetryProjectRunItem($this->projectRun->project),
            403,
        );

        $preview = app(ContentProjectStepRerunService::class)->previewBulk(
            $this->projectRun,
            $this->projectRun->project,
            $taskIds,
            $nodeId,
        );
        $this->skipRender();

        return [
            'success' => (bool) $preview['can_execute'],
            'message' => (string) $preview['message'],
            'preview' => $preview,
        ];
    }

    /**
     * @return array{success: bool, message: string, created?: int, skipped?: int, failed?: int}
     */
    public function bulkRerunGenericStep(array $taskIds, string $nodeId, bool $allowPartial = false): array
    {
        if ($this->projectRun === null) {
            $this->skipRender();

            return ['success' => false, 'message' => 'Run không tồn tại.'];
        }

        if ($this->shouldBlockPhpEngineArticleMutation('bulkRerunGenericStep')) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => 'PHP engine đang chạy — không bulk rerun khi run còn active.',
            ];
        }

        abort_unless(
            SeoAccessControl::canRetryProjectRunItem($this->projectRun->project),
            403,
        );

        $result = app(ContentProjectStepRerunService::class)->executeBulkSerial(
            $this->projectRun,
            $this->projectRun->project,
            $taskIds,
            $nodeId,
            ContentProjectStepRerunMode::SingleStep,
            $allowPartial,
            auth()->id() !== null ? (int) auth()->id() : null,
        );
        $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);
        $this->skipRender();

        return [
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'created' => (int) ($result['created'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getGenericPickerSteps(): array
    {
        if ($this->projectRun === null) {
            return [];
        }

        $task = $this->projectRun->project?->tasks?->first();
        if (! $task instanceof SeoProjectTask) {
            $task = SeoProjectTask::query()
                ->where('project_id', (int) $this->projectRun->project_id)
                ->orderBy('id')
                ->first();
        }
        if (! $task instanceof SeoProjectTask) {
            return [];
        }

        return array_map(
            static fn ($d) => $d->toArray(),
            app(SeoProjectWorkflowStepCatalogService::class)->listGenericPickerSteps($task),
        );
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     cancelled: int,
     *     already_idle?: bool,
     *     match_mode?: string,
     *     active_before?: int,
     *     active_after?: int,
     *     affected_item_ids?: list<int>,
     *     step_action?: string,
     *     article_id?: int
     * }
     */
    public function cancelWorkflowStep(int $taskId, string $nodeId): array
    {
        if ($this->projectRun === null) {
            $this->skipRender();

            return ['success' => false, 'message' => 'Run không tồn tại.', 'cancelled' => 0];
        }

        abort_unless(
            SeoAccessControl::canRetryProjectRunItem($this->projectRun->project),
            403,
        );

        try {
            $result = app(SeoProjectWorkflowStepRetryService::class)->cancelActiveStep(
                $this->projectRun,
                $taskId,
                $nodeId,
            );
            $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);
            $this->skipRender();

            return [
                'success' => (bool) ($result['success'] ?? false),
                'message' => (string) ($result['message'] ?? ''),
                'cancelled' => (int) ($result['cancelled'] ?? 0),
                'already_idle' => (bool) ($result['already_idle'] ?? false),
                'match_mode' => (string) ($result['match_mode'] ?? ''),
                'active_before' => (int) ($result['active_before'] ?? 0),
                'active_after' => (int) ($result['active_after'] ?? 0),
                'affected_item_ids' => array_values(array_map(
                    'intval',
                    is_array($result['affected_item_ids'] ?? null) ? $result['affected_item_ids'] : [],
                )),
                'step_action' => (string) ($result['step_action'] ?? ''),
                'article_id' => (int) ($result['article_id'] ?? 0),
            ];
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'seo.project_run.cancel_workflow_step',
                'run_id' => (int) $this->projectRun->id,
                'task_id' => $taskId,
                'node_id' => $nodeId,
            ]);
            $this->skipRender();

            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'cancelled' => 0,
            ];
        }
    }

    /**
     * @param  list<int|string>  $taskIds
     * @param  list<string>  $nodeIds
     * @return array{success: bool, message: string, created: int, skipped: int, failed: int}
     */
    public function bulkRetryWorkflowSteps(array $taskIds, array $nodeIds): array
    {
        if ($this->projectRun === null) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => 'Run không tồn tại.',
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];
        }

        abort_unless(
            SeoAccessControl::canRetryProjectRunItem($this->projectRun->project),
            403,
        );

        try {
            $bulk = app(SeoProjectWorkflowStepRetryService::class)->enqueueBulk(
                $this->projectRun,
                $this->projectRun->project,
                $taskIds,
                $nodeIds,
                executeImmediately: true,
            );
            $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);
            $this->skipRender();

            return [
                'success' => ($bulk['failed'] ?? 0) === 0 && ($bulk['created'] ?? 0) > 0,
                'message' => (string) ($bulk['message'] ?? ''),
                'created' => (int) ($bulk['created'] ?? 0),
                'skipped' => (int) ($bulk['skipped'] ?? 0),
                'failed' => (int) ($bulk['failed'] ?? 0),
            ];
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'seo.project_run.bulk_retry_workflow_steps',
                'run_id' => (int) $this->projectRun->id,
            ]);
            $this->skipRender();

            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];
        }
    }

    /**
     * @param  list<int|string>  $taskIds
     * @return array<string, mixed>
     */
    public function previewBulkRerunByAction(array $taskIds, string $action): array
    {
        if ($this->projectRun === null) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => 'Run không tồn tại.',
                'can_execute' => false,
                'valid_count' => 0,
                'invalid_count' => 0,
            ];
        }

        abort_unless(
            SeoAccessControl::canRetryProjectRunItem($this->projectRun->project),
            403,
        );

        try {
            $preview = app(ContentProjectBulkRerunService::class)->preview(
                $this->projectRun,
                $this->projectRun->project,
                $taskIds,
                $action,
            );
            $this->skipRender();

            return array_merge(['success' => true], $preview);
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'seo.project_run.preview_bulk_rerun_by_action',
                'run_id' => (int) $this->projectRun->id,
            ]);
            $this->skipRender();

            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'can_execute' => false,
                'valid_count' => 0,
                'invalid_count' => 0,
            ];
        }
    }

    /**
     * @param  list<int|string>  $taskIds
     * @return array{success: bool, message: string, created: int, skipped: int, failed: int}
     */
    public function bulkRerunByAction(array $taskIds, string $action, bool $allowPartial = false): array
    {
        if ($this->projectRun === null) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => 'Run không tồn tại.',
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];
        }

        abort_unless(
            SeoAccessControl::canRetryProjectRunItem($this->projectRun->project),
            403,
        );

        try {
            $result = app(ContentProjectBulkRerunService::class)->execute(
                $this->projectRun,
                $this->projectRun->project,
                $taskIds,
                $action,
                $allowPartial,
            );
            $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);
            $this->skipRender();

            return [
                'success' => (bool) ($result['success'] ?? false),
                'message' => (string) ($result['message'] ?? ''),
                'created' => (int) ($result['created'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
            ];
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'seo.project_run.bulk_rerun_by_action',
                'run_id' => (int) $this->projectRun->id,
            ]);
            $this->skipRender();

            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];
        }
    }

    /**
     * @return array{has_outline_role: bool, has_content_role: bool}
     */
    public function getBulkRerunRoleAvailability(): array
    {
        $project = $this->projectRun?->project;
        if ($project === null) {
            return [
                'has_outline_role' => false,
                'has_content_role' => false,
            ];
        }

        $catalog = app(SeoProjectWorkflowStepCatalogService::class);
        foreach ($project->tasks ?? [] as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            if (SeoProjectTask::isManualRunType((string) $task->type)) {
                continue;
            }
            if ($catalog->resolveSeoTaskForStepRetry($task) === null) {
                continue;
            }

            return [
                'has_outline_role' => $catalog->hasRole($task, WorkflowExecutionRole::ArticleOutlineGenerate),
                'has_content_role' => $catalog->hasRole($task, WorkflowExecutionRole::ArticleContentGenerate),
            ];
        }

        return [
            'has_outline_role' => false,
            'has_content_role' => false,
        ];
    }

    /**
     * @return list<int>
     */
    public function getQueueTaskIds(): array
    {
        if ($this->projectRun === null) {
            return [];
        }

        // Chỉ đếm hạng mục đã kết thúc (không tính pending đã seed) để remainingSlots đúng.
        $processedInRun = collect($this->getResultItems())
            ->filter(static fn (array $item): bool => ! in_array((string) ($item['status'] ?? ''), ['pending'], true))
            ->count();

        $plannedTotal = (int) $this->projectRun->total;
        $remainingSlots = $plannedTotal > 0
            ? max(0, $plannedTotal - $processedInRun)
            : PHP_INT_MAX;

        if ($remainingSlots === 0) {
            return [];
        }

        $taskIds = [];

        foreach ($this->getAllItems() as $item) {
            if ((string) ($item['status'] ?? '') !== 'pending') {
                continue;
            }

            if (SeoProjectTask::isManualRunType((string) ($item['type'] ?? ''))) {
                continue;
            }

            if ((bool) ($item['article_is_reviewed'] ?? false)) {
                continue;
            }

            $taskId = (int) ($item['task_id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }

            if (! (bool) ($item['task_exists'] ?? true)) {
                continue;
            }

            if (! (bool) ($item['can_retry'] ?? true)) {
                continue;
            }

            $taskIds[] = $taskId;

            if (count($taskIds) >= $remainingSlots) {
                break;
            }
        }

        return $taskIds;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueueBootstrapData(): array
    {
        if ($this->projectRun !== null) {
            try {
                ContentProjectRunEngineFeature::ensureStamped($this->projectRun);
                $this->projectRun->refresh();
            } catch (\Throwable) {
                // Read-only bootstrap — không fail page nếu stamp lazy lỗi.
            }
        }

        $orchestration = $this->projectRun !== null
            ? ContentProjectRunEngineFeature::orchestrationFor($this->projectRun)
            : ContentProjectRunEngineFeature::ORCHESTRATION_LEGACY;
        $phpEngine = $orchestration === ContentProjectRunEngineFeature::ORCHESTRATION_PHP;
        $runStatus = (string) ($this->projectRun?->status ?? '');
        $engineUiRunning = in_array($runStatus, [
            SeoProjectRun::STATUS_RUNNING,
            SeoProjectRun::STATUS_STOPPING,
        ], true);

        return [
            'livewireId' => $this->getId(),
            'runStatus' => $runStatus,
            'engineUiRunning' => $engineUiRunning,
            'taskIds' => $this->getQueueTaskIds(),
            'rerunAllTaskIds' => [],
            'canRerunAll' => false,
            'canRetryWorkflowSteps' => $this->canRetryWorkflowSteps(),
            'workflowSteps' => $this->getBulkWorkflowSteps(),
            'genericPickerSteps' => $this->getGenericPickerSteps(),
            'roleAvailability' => $this->getBulkRerunRoleAvailability(),
            'bulkActions' => [
                ContentProjectBulkRerunService::ACTION_OUTLINE => [
                    'value' => ContentProjectBulkRerunService::ACTION_OUTLINE,
                    'label' => 'Tạo lại dàn ý',
                    'description' => 'Chạy lại node outline. Không chạy lại bài viết.',
                    'requires_outline' => true,
                    'requires_content' => false,
                ],
                ContentProjectBulkRerunService::ACTION_ARTICLE => [
                    'value' => ContentProjectBulkRerunService::ACTION_ARTICLE,
                    'label' => 'Tạo lại bài từ dàn ý',
                    'description' => 'Dùng dàn ý hiện tại, chỉ chạy node viết bài.',
                    'requires_outline' => false,
                    'requires_content' => true,
                ],
                ContentProjectBulkRerunService::ACTION_OUTLINE_AND_ARTICLE => [
                    'value' => ContentProjectBulkRerunService::ACTION_OUTLINE_AND_ARTICLE,
                    'label' => 'Tạo lại dàn ý và bài viết',
                    'description' => 'Tạo outline mới rồi viết bài từ artifact đó.',
                    'requires_outline' => true,
                    'requires_content' => true,
                ],
            ],
            'canSyncAll' => $this->canSyncAllItems(),
            'canArchiveItems' => false,
            'runSettings' => ContentProjectRunSettings::fromRun($this->projectRun)->toArray(),
            // PHP engine: never autorun JS orchestration (even if ?autorun=1 in URL).
            'autorun' => $phpEngine ? false : request()->boolean('autorun'),
            'phpEngine' => $phpEngine,
            'orchestration' => $orchestration,
            'engineLabel' => $phpEngine ? 'PHP' : 'Legacy',
            'progressPollMs' => $phpEngine ? 3000 : 0,
            'labels' => [
                'running' => __('seo-content-ai::filament.projects.run_queue_running'),
                'ok' => 'OK',
                'failed' => __('seo-content-ai::filament.projects.run_item_failed'),
                'pending' => __('seo-content-ai::filament.projects.run_item_pending'),
                'archiveConfirm' => __('seo-content-ai::filament.projects.archive_item_confirm'),
                'bulkSelected' => 'Đã chọn :count bài',
                'bulkPickPrompt' => 'Chọn prompt',
                'bulkExecute' => 'Thực hiện',
                'bulkConfirmHeading' => 'Xác nhận tạo lại',
                'bulkConfirmBody' => 'Action: :action — Hợp lệ: :valid — Không hợp lệ: :invalid. Workflow: :workflow.',
                'bulkActionOutline' => 'Tạo lại dàn ý',
                'bulkActionArticle' => 'Tạo lại bài từ dàn ý',
                'bulkActionOutlineAndArticle' => 'Tạo lại dàn ý và bài viết',
                'bulkActionOutlineHelp' => 'Chạy lại node outline. Không chạy lại bài viết.',
                'bulkActionArticleHelp' => 'Dùng dàn ý hiện tại, chỉ chạy node viết bài.',
                'bulkActionOutlineAndArticleHelp' => 'Tạo outline mới rồi viết bài từ artifact đó.',
                'bulkActionGenericStep' => 'Chạy lại bước...',
                'bulkArchive' => __('seo-content-ai::filament.projects.archive_item'),
                'runSettingsHeading' => __('seo-content-ai::filament.projects.run_settings_heading'),
                'runSettingsGeneratePostImages' => __('seo-content-ai::filament.projects.run_settings_generate_post_images'),
                'runSettingsGeneratePostImagesHelp' => __('seo-content-ai::filament.projects.run_settings_generate_post_images_help'),
                'runSettingsStart' => __('seo-content-ai::filament.projects.run_settings_start'),
                'runSettingsCancel' => __('seo-content-ai::filament.projects.run_settings_cancel'),
                'syncAll' => __('seo-content-ai::filament.projects.run_sync_all'),
                'syncAllConfirmHeading' => __('seo-content-ai::filament.projects.run_sync_all_confirm_heading'),
                'syncAllConfirmBody' => __('seo-content-ai::filament.projects.run_sync_all_confirm_body'),
                'syncAllCancel' => __('seo-content-ai::filament.projects.run_settings_cancel'),
                'stop' => __('seo-content-ai::filament.projects.run_stop'),
                'stopping' => __('seo-content-ai::filament.projects.run_stopping'),
                'retryItemConfirm' => __('seo-content-ai::filament.projects.run_retry_item_confirm'),
                'rerunBadgeTooltip' => __('seo-content-ai::filament.projects.run_item_rerun_badge_tooltip', [
                    'count' => ':count',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, int|string>
     */
    public function getRunStatsPayload(): array
    {
        if ($this->projectRun === null) {
            return [
                'total' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'pending' => 0,
                'status' => '',
            ];
        }

        $counters = app(SeoProjectRunItemsReader::class)->aggregateCounters($this->projectRun);

        return [
            'total' => $counters['total'],
            'succeeded' => $counters['succeeded'],
            'failed' => $counters['failed'],
            'pending' => $counters['pending'],
            'status' => (string) ($this->projectRun->status ?? ''),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getResultItems(): array
    {
        if ($this->projectRun === null) {
            return [];
        }

        $items = app(SeoProjectRunItemsReader::class)->forRunAsArrays($this->projectRun);

        // Ẩn khi task đã có archived_at (kể cả soft-delete). Hàng kẹt
        // "Task gốc không còn tồn tại" (soft-delete chưa archived_at) vẫn hiện + nút Archive.
        return array_values(array_filter(
            $items,
            static fn (array $item): bool => ! (bool) ($item['task_archived'] ?? false),
        ));
    }

    /**
     * Pending tasks của project (section riêng) — không merge vào run result.
     *
     * @return list<array<string, mixed>>
     */
    public function getPendingItems(): array
    {
        $project = $this->projectRun?->project;
        if ($project === null) {
            return [];
        }

        return $project->tasks()
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->planned()
            ->orderBy('target_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SeoProjectTask $task): array => [
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
                'task_exists' => true,
                'can_retry' => true,
                'can_archive' => false,
                'source' => 'project_pending',
                'is_legacy' => false,
            ])
            ->all();
    }

    /**
     * Run execution items only — không union pending project tasks.
     *
     * @return list<array<string, mixed>>
     */
    public function getAllItems(): array
    {
        $items = app(SeoProjectRunItemsDisplayPresenter::class)->consolidate($this->getResultItems());

        $enriched = array_map(
            fn (array $item): array => $this->enrichItemWorkflowSteps(
                $this->enrichItemLastSaved(
                    $this->enrichItemRewriteMeta($this->enrichItemArticleLink($item))
                )
            ),
            $items,
        );

        usort($enriched, static function (array $left, array $right): int {
            $leftRun = strtotime((string) ($left['last_run_at'] ?? '')) ?: 0;
            $rightRun = strtotime((string) ($right['last_run_at'] ?? '')) ?: 0;
            if ($leftRun !== $rightRun) {
                return $rightRun <=> $leftRun;
            }

            $leftDate = (string) ($left['target_date'] ?? '');
            $rightDate = (string) ($right['target_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return $rightDate <=> $leftDate;
            }

            return ((int) ($right['task_id'] ?? 0)) <=> ((int) ($left['task_id'] ?? 0));
        });

        return $enriched;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemKeywordLabel(array $item): string
    {
        $articleTitle = trim((string) ($item['article_title'] ?? ''));
        if ($articleTitle !== '') {
            return $articleTitle;
        }

        $label = trim((string) ($item['source_content'] ?? ''));

        return $label !== '' ? $label : '—';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemKeywordEditUrl(array $item): ?string
    {
        $articleId = (int) ($item['article_id'] ?? 0);
        if ($articleId > 0) {
            if (! (bool) ($item['article_editor_ready'] ?? app(ArticleEditorReadinessService::class)->isReady($articleId))) {
                return null;
            }

            return ArticleResource::getUrl('edit', ['record' => $articleId]);
        }

        $url = trim((string) ($item['article_edit_url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * @return array{ready: bool, edit_url: ?string, message: string}
     */
    public function checkArticleEditorReady(int $articleId): array
    {
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return [
                'ready' => false,
                'edit_url' => null,
                'message' => __('seo-content-ai::filament.projects.article_editor_preparing_body'),
            ];
        }

        $readiness = app(ArticleEditorReadinessService::class)->evaluate($article);

        return [
            'ready' => $readiness->isReady,
            'edit_url' => $readiness->isReady
                ? ArticleResource::getUrl('edit', ['record' => $articleId])
                : null,
            'message' => app(ArticleEditorReadinessService::class)->userMessage($readiness),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemRunDate(array $item): string
    {
        $targetDate = trim((string) ($item['target_date'] ?? ''));
        if ($targetDate !== '') {
            return $targetDate;
        }

        if ($this->projectRun?->started_at !== null) {
            return $this->projectRun->started_at->format('Y-m-d');
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemLastRunAt(array $item): string
    {
        $raw = trim((string) ($item['last_run_at'] ?? ''));
        if ($raw === '') {
            return '—';
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw)->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return $raw;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemStepsUrl(array $item): ?string
    {
        $articleId = (int) ($item['article_id'] ?? 0);
        if ($articleId <= 0 || $this->projectRun === null) {
            return null;
        }

        return SeoProjectResource::getUrl('view-run-step', [
            'run' => $this->projectRun,
            'article' => $articleId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function enrichItemArticleLink(array $item): array
    {
        $articleId = (int) ($item['article_id'] ?? 0);

        if ($articleId <= 0) {
            $taskId = (int) ($item['task_id'] ?? 0);
            if ($taskId > 0) {
                $fromTask = (int) ($this->projectRun?->project?->tasks
                    ?->firstWhere('id', $taskId)
                    ?->article_id ?? 0);
                if ($fromTask <= 0) {
                    $fromTask = (int) ($this->projectRun?->project?->tasks()
                        ->whereKey($taskId)
                        ->value('article_id') ?? 0);
                }
                if ($fromTask > 0) {
                    $articleId = $fromTask;
                    $item['article_id'] = $fromTask;
                }
            }
        }

        if ($articleId > 0) {
            $article = SeoArticle::query()
                ->select(['id', 'title', 'is_reviewed', 'last_manual_saved_at', 'last_synced_at'])
                ->whereKey($articleId)
                ->first();

            $item['article_edit_url'] = ArticleResource::getUrl('edit', ['record' => $articleId]);
            $readiness = app(ArticleEditorReadinessService::class)->evaluate(
                SeoArticle::query()->find($articleId) ?? $article,
            );
            $item['article_editor_ready'] = $readiness->isReady;
            if (! $readiness->isReady) {
                $item['article_edit_url'] = null;
                $item['article_editor_preparing_message'] = app(ArticleEditorReadinessService::class)->userMessage($readiness);
            }
            $item['article_is_reviewed'] = (bool) ($article?->is_reviewed ?? false);
            $item['article_title'] = trim((string) ($article?->title ?? ''));
            $item['last_manual_saved_at'] = $article?->last_manual_saved_at?->toIso8601String();
            $item['last_synced_at'] = $article?->last_synced_at?->toIso8601String();

            return $item;
        }

        $source = trim((string) ($item['source_content'] ?? ''));
        if ($source === '') {
            return $item;
        }

        $resolvedId = $this->resolveArticleIdForSource($source);
        if ($resolvedId > 0) {
            $article = SeoArticle::query()
                ->select(['id', 'title', 'is_reviewed', 'last_manual_saved_at', 'last_synced_at'])
                ->whereKey($resolvedId)
                ->first();

            $item['article_id'] = $resolvedId;
            $fullArticle = SeoArticle::query()->find($resolvedId);
            $readiness = $fullArticle instanceof SeoArticle
                ? app(ArticleEditorReadinessService::class)->evaluate($fullArticle)
                : new \App\Addons\SeoContentAi\Services\ArticleEditorReadinessResult(isReady: false, reasons: ['missing_article']);
            $item['article_editor_ready'] = $readiness->isReady;
            $item['article_edit_url'] = $readiness->isReady
                ? ArticleResource::getUrl('edit', ['record' => $resolvedId])
                : null;
            if (! $readiness->isReady) {
                $item['article_editor_preparing_message'] = app(ArticleEditorReadinessService::class)->userMessage($readiness);
            }
            $item['article_is_reviewed'] = (bool) ($article?->is_reviewed ?? false);
            $item['article_title'] = trim((string) ($article?->title ?? ''));
            $item['last_manual_saved_at'] = $article?->last_manual_saved_at?->toIso8601String();
            $item['last_synced_at'] = $article?->last_synced_at?->toIso8601String();
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function enrichItemLastSaved(array $item): array
    {
        $change = app(ArticleLastContentChangeResolver::class)->resolve([
            'last_manual_saved_at' => $item['last_manual_saved_at'] ?? null,
            'last_synced_at' => $item['last_synced_at'] ?? null,
            'last_ai_content_at' => $item['last_ai_content_at'] ?? null,
        ]);

        $item['last_saved_display'] = $change->relative !== '—'
            ? $change->relative
            : $change->display;
        $item['last_saved_absolute'] = $change->absolute;
        $item['last_saved_source'] = $change->source;
        $item['last_saved_source_label'] = $change->sourceLabel;
        $item['last_saved_tooltip'] = $change->absolute !== null
            ? ($change->absolute.($change->sourceLabel ? ' · Nguồn: '.$change->sourceLabel : ''))
            : null;

        $rowStatus = app(ContentProjectArticleRowStatusResolver::class)->resolve($item);
        $item['row_status'] = $rowStatus->toArray();
        $item['row_status_label'] = $rowStatus->label;
        $item['row_status_code'] = $rowStatus->code;
        $item['row_status_tooltip'] = $rowStatus->tooltip;

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function enrichItemWorkflowSteps(array $item): array
    {
        $taskId = (int) ($item['task_id'] ?? 0);
        if ($taskId <= 0 || $this->projectRun === null || $this->itemIsImproveType($item)) {
            $item['workflow_steps'] = [];

            return $item;
        }

        $task = SeoProjectTask::query()->whereKey($taskId)->first();
        if (! $task instanceof SeoProjectTask) {
            $item['workflow_steps'] = [];

            return $item;
        }

        $item['workflow_steps'] = app(SeoProjectWorkflowStepRetryService::class)
            ->stepsForTask($this->projectRun, $task);

        return $item;
    }

    private function resolveArticleIdForSource(string $source): int
    {
        $projectSiteId = (int) ($this->projectRun?->project?->site_id ?? 0);
        $normalized = mb_strtolower($source);
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $source);

        $baseQuery = function () use ($projectSiteId): Builder {
            $query = SeoArticle::query();

            if (SeoAccessControl::shouldScopeToAccountOwner()) {
                SeoAccessControl::applyAccessibleSiteScope($query);
            }

            if ($projectSiteId > 0) {
                $query->where('site_id', $projectSiteId);
            }

            return $query;
        };

        $byTitle = $baseQuery()
            ->where('title', $source)
            ->orderByDesc('id')
            ->value('id');

        if ($byTitle !== null) {
            return (int) $byTitle;
        }

        $byTitleLike = $baseQuery()
            ->where('title', 'like', '%'.$like.'%')
            ->orderByDesc('id')
            ->value('id');

        if ($byTitleLike !== null) {
            return (int) $byTitleLike;
        }

        return (int) ($baseQuery()
            ->whereHas('articleMetas', function (Builder $query) use ($normalized, $like): void {
                $query->where('meta_key', 'seo_focus_keyword')
                    ->where(function (Builder $inner) use ($normalized, $like): void {
                        $inner->whereRaw('LOWER(meta_value) = ?', [$normalized])
                            ->orWhere('meta_value', 'like', '%'.$like.'%');
                    });
            })
            ->orderByDesc('id')
            ->value('id') ?? 0);
    }

    public function getPendingCount(): int
    {
        return collect($this->getAllItems())
            ->where('status', 'pending')
            ->count();
    }

    public function isDebugMode(): bool
    {
        return app(SeoProjectRunErrorFormatter::class)->isDebug();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function displayItemError(array $item): string
    {
        return app(SeoProjectRunErrorFormatter::class)->displayMessage($item);
    }

    public function postTypeLabel(?string $postType): string
    {
        if ($postType === null || $postType === '') {
            return '—';
        }

        return SeoProjectResource::postTypeSelectOptions()[$postType] ?? $postType;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemTypeLabel(array $item): string
    {
        if ($this->itemIsImproveType($item)) {
            return __('seo-content-ai::filament.projects.run_type_improve');
        }

        if (($item['type'] ?? '') === SeoProjectTask::TYPE_REWRITE) {
            $mode = SeoProjectTask::normalizeRewriteMode($item['rewrite_mode'] ?? null);
            $modeLabel = SeoProjectTask::rewriteModeOptions()[$mode] ?? $mode;

            return __('seo-content-ai::filament.projects.run_type_rewrite').' ('.$modeLabel.')';
        }

        return __('seo-content-ai::filament.projects.run_type_new');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemRewriteNotes(array $item): ?string
    {
        if (($item['type'] ?? '') !== SeoProjectTask::TYPE_REWRITE) {
            return null;
        }

        $notes = trim((string) ($item['rewrite_notes'] ?? ''));

        return $notes !== '' ? $notes : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function enrichItemRewriteMeta(array $item): array
    {
        if (($item['type'] ?? '') !== SeoProjectTask::TYPE_REWRITE) {
            return $item;
        }

        $taskId = (int) ($item['task_id'] ?? 0);
        if ($taskId > 0) {
            $task = $this->projectRun?->project?->tasks?->firstWhere('id', $taskId);
            if ($task instanceof SeoProjectTask) {
                $item['rewrite_mode'] = SeoProjectTask::normalizeRewriteMode($task->rewrite_mode);
                $item['rewrite_notes'] = $task->rewrite_notes;
            }
        }

        $item['rewrite_mode'] = SeoProjectTask::normalizeRewriteMode($item['rewrite_mode'] ?? null);

        $notes = trim((string) ($item['rewrite_notes'] ?? ''));
        if (
            $item['rewrite_mode'] !== SeoProjectTask::REWRITE_MODE_CONTENT
            || $notes === ''
        ) {
            $item['rewrite_notes'] = null;
        } else {
            $item['rewrite_notes'] = $notes;
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemIsImproveType(array $item): bool
    {
        return SeoProjectTask::isManualRunType((string) ($item['type'] ?? ''));
    }

    public function runItem(int $taskId): void
    {
        if ($this->projectRun === null) {
            return;
        }

        if ($this->shouldBlockPhpEngineArticleMutation('runItem')) {
            Notification::make()
                ->title('PHP Engine đang chạy')
                ->body('Không chạy lại article khi run PHP còn active. Đợi terminal hoặc Stop trước.')
                ->warning()
                ->send();

            return;
        }

        if (! SeoAccessControl::canRetryProjectRunItem($this->projectRun->project)) {
            abort(403, __('seo-content-ai::filament.projects.run_retry_failed'));
        }

        if ($this->isImproveTaskId($taskId)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_item_failed'))
                ->body(__('seo-content-ai::filament.projects.run_item_manual_hint'))
                ->warning()
                ->send();

            return;
        }

        $formatter = app(SeoProjectRunErrorFormatter::class);

        try {
            $resolvedArticleId = $this->syncResolvedArticleIdForRunTask($taskId);
            $item = app(SeoProjectWorkflowRunService::class)->retryTask(
                $this->projectRun,
                $taskId,
                forcedArticleId: $resolvedArticleId > 0 ? $resolvedArticleId : null,
            );
            $this->projectRun->refresh();

            if (($item['status'] ?? '') === 'success') {
                Notification::make()
                    ->title(__('seo-content-ai::filament.projects.run_item_success'))
                    ->body((string) ($item['message'] ?? ''))
                    ->success()
                    ->send();

                $this->reloadCurrentRunPage();

                return;
            }

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_item_failed'))
                ->body($formatter->displayMessage($item))
                ->danger()
                ->persistent()
                ->send();

            $this->reloadCurrentRunPage();
        } catch (\Throwable $exception) {
            $error = $formatter->fromThrowable($exception);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_item_failed'))
                ->body($formatter->displayMessage([
                    'status' => 'failed',
                    'message' => $error['message'],
                    'error_detail' => $error['error_detail'],
                ]))
                ->danger()
                ->persistent()
                ->send();

            $this->reloadCurrentRunPage();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function runItemQueued(int $taskId, bool $markCompleted = false): array
    {
        // PHP engine owns article orchestration while run active.
        // Terminal PHP run: cho phép manual retry (adapter ExecutionService).
        if ($this->shouldBlockPhpEngineArticleMutation('runItemQueued')) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => 'PHP engine đang bật — không chạy article qua Livewire queue khi run còn active. Dùng Start/Stop của engine, hoặc retry sau khi run terminal.',
                'stats' => $this->getRunStatsPayload(),
            ];
        }

        RuntimeLogger::info('seo.project_run.runItemQueued.called', [
            'run_id' => (int) ($this->projectRun?->id ?? 0),
            'task_id' => $taskId,
            'mark_completed' => $markCompleted,
            'user_id' => (int) auth()->id(),
        ]);

        if ($this->projectRun === null) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => 'Run không tồn tại.',
            ];
        }

        if (! SeoAccessControl::canRetryProjectRunItem($this->projectRun->project)) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => __('seo-content-ai::filament.projects.run_retry_failed'),
            ];
        }

        if ($this->isImproveTaskId($taskId)) {
            $this->skipRender();

            return [
                'success' => false,
                'message' => __('seo-content-ai::filament.projects.run_item_manual_hint'),
            ];
        }

        $formatter = app(SeoProjectRunErrorFormatter::class);

        try {
            $resolvedArticleId = $this->syncResolvedArticleIdForRunTask($taskId);
            $item = app(SeoProjectWorkflowRunService::class)->retryTask(
                $this->projectRun,
                $taskId,
                markCompleted: $markCompleted,
                forcedArticleId: $resolvedArticleId > 0 ? $resolvedArticleId : null,
            );
            $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);

            $enriched = $this->enrichItemRewriteMeta($this->enrichItemArticleLink($item));
            $itemStatus = (string) ($enriched['status'] ?? '');
            $isSuccess = $itemStatus === 'success';

            RuntimeLogger::info('seo.project_run.runItemQueued.done', [
                'run_id' => (int) $this->projectRun->id,
                'task_id' => $taskId,
                'item_status' => $itemStatus,
                'last_run_at' => (string) ($enriched['last_run_at'] ?? ''),
                'message' => (string) ($enriched['message'] ?? ''),
                'debug' => $enriched['debug'] ?? null,
                'step_stats' => $enriched['step_stats'] ?? null,
            ]);

            return [
                'success' => $isSuccess,
                'item' => $enriched,
                'displayError' => $formatter->displayMessage($item),
                'message' => $isSuccess
                    ? (string) ($enriched['message'] ?? '')
                    : $formatter->displayMessage($item),
                'stats' => $this->getRunStatsPayload(),
            ];
        } catch (\Throwable $exception) {
            RuntimeLogger::error('seo.project_run.runItemQueued.exception', [
                'run_id' => (int) ($this->projectRun?->id ?? 0),
                'task_id' => $taskId,
                'error' => $exception->getMessage(),
                'class' => $exception::class,
            ]);

            $error = $formatter->fromThrowable($exception);

            return [
                'success' => false,
                'message' => $formatter->displayMessage([
                    'status' => 'failed',
                    'message' => $error['message'],
                    'error_detail' => $error['error_detail'],
                ]),
                'stats' => $this->getRunStatsPayload(),
            ];
        } finally {
            $this->skipRender();
        }
    }

    /**
     * Kết thúc queue thủ công (chạy lẻ / rerun) — cập nhật counter, không toast, không consolidate/redirect.
     */
    public function finalizePartialQueue(): void
    {
        if ($this->projectRun === null) {
            return;
        }

        abort_unless(SeoAccessControl::canRetryProjectRunItem($this->projectRun->project), 403);

        $this->projectRun->refresh();
        app(SeoProjectWorkflowRunService::class)->markRunCompletedQuietly($this->projectRun);
        $this->projectRun->refresh();
        $this->skipRender();
    }

    public function canSyncAllItems(): bool
    {
        if ($this->projectRun === null) {
            return false;
        }

        return SeoAccessControl::canSyncArticlesToWordPress()
            && SeoAccessControl::canAccessContentProjectRun($this->projectRun->project);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateRunSettingsForRerun(array $settings): array
    {
        if ($this->projectRun === null) {
            return ['success' => false, 'message' => 'Run không tồn tại.'];
        }

        abort_unless(SeoAccessControl::canRetryProjectRunItem($this->projectRun->project), 403);

        $validated = validator($settings, [
            'generate_post_images' => ['nullable', 'boolean'],
        ])->validate();

        $this->projectRun = app(SeoProjectWorkflowRunService::class)
            ->updateRunSettings($this->projectRun, $validated);

        return [
            'success' => true,
            'settings' => ContentProjectRunSettings::fromRun($this->projectRun)->toArray(),
        ];
    }

    /**
     * @return array{success: bool, message: string, queued?: int, skipped?: int}
     */
    public function syncAllCompleted(): array
    {
        if ($this->projectRun === null) {
            return ['success' => false, 'message' => 'Run không tồn tại.'];
        }

        abort_unless($this->canSyncAllItems(), 403);

        $actor = auth()->user();
        if ($actor === null) {
            return ['success' => false, 'message' => 'Chưa đăng nhập.'];
        }

        $result = app(ContentProjectRunBulkSyncService::class)
            ->dispatchEligibleArticles($this->projectRun, $actor);

        $queued = (int) ($result['queued'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.run_sync_all_done_title'))
            ->body(__('seo-content-ai::filament.projects.run_sync_all_done_body', [
                'queued' => $queued,
                'skipped' => $skipped,
            ]))
            ->success()
            ->send();

        return [
            'success' => true,
            'message' => __('seo-content-ai::filament.projects.run_sync_all_done_body', [
                'queued' => $queued,
                'skipped' => $skipped,
            ]),
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }

    public function beginRunQueue(): void
    {
        if ($this->denyLegacyOrchestrationAction('beginRunQueue')) {
            return;
        }

        if ($this->projectRun === null) {
            return;
        }

        abort_unless(SeoAccessControl::canRetryProjectRunItem($this->projectRun->project), 403);

        if ($this->projectRun->status !== SeoProjectRun::STATUS_RUNNING) {
            $this->projectRun->update([
                'status' => SeoProjectRun::STATUS_RUNNING,
                'finished_at' => null,
            ]);
            $this->projectRun->refresh();
        }

        $this->skipRender();
    }

    public function completeRunQueue(bool $stopped = false): void
    {
        // PHP engine finalizes run — JS must not complete.
        if ($this->denyLegacyOrchestrationAction('completeRunQueue')) {
            return;
        }
        if ($this->projectRun === null) {
            return;
        }

        if ($stopped) {
            try {
                app(SeoProjectWorkflowStepRetryService::class)
                    ->cancelAllActiveSteps($this->projectRun);
            } catch (\Throwable) {
                // Best-effort.
            }
        }

        if ($this->projectRun->status === SeoProjectRun::STATUS_RUNNING) {
            $completedRun = app(SeoProjectWorkflowRunService::class)->completeRunQueue($this->projectRun);
            if ((int) $completedRun->id !== (int) $this->projectRun->id) {
                $this->redirect(
                    SeoProjectResource::getUrl('view-run', ['run' => $completedRun->id]),
                    navigate: false,
                );

                return;
            }

            $this->projectRun->refresh();
        } elseif ($stopped) {
            // Run không còn running (vd. đã completed rồi bị reopen lỗi) — vẫn đảm bảo finished.
            app(SeoProjectWorkflowRunService::class)->markRunCompletedQuietly($this->projectRun);
            $this->projectRun->refresh();
        }

        if ($stopped) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_stopped'))
                ->body(__('seo-content-ai::filament.projects.run_stopped_body'))
                ->warning()
                ->send();

            return;
        }

        $run = $this->projectRun;
        $notification = Notification::make()
            ->title(__('seo-content-ai::filament.projects.run_completed'))
            ->body(__('seo-content-ai::filament.projects.run_completed_body', [
                'succeeded' => (int) $run->succeeded,
                'failed' => (int) $run->failed,
                'total' => (int) $run->total,
            ]));

        if ((int) $run->failed > 0) {
            $notification->warning()->send();
        } else {
            $notification->success()->send();
        }
    }

    /**
     * Stop ngay lập tức — không đợi item đang treo xong.
     *
     * @return array{success: bool, message: string, cancelled_steps: int}
     */
    public function forceStopRunQueue(): array
    {
        if ($this->projectRun === null) {
            $this->skipRender();

            return ['success' => false, 'message' => 'Run không tồn tại.', 'cancelled_steps' => 0];
        }

        abort_unless(
            SeoAccessControl::canRetryProjectRunItem($this->projectRun->project),
            403,
        );

        if ($this->projectRun !== null && ContentProjectRunEngineFeature::enabledFor($this->projectRun)) {
            app(ContentProjectRunEngine::class)->requestStop(
                $this->projectRun,
                auth()->id() !== null ? (int) auth()->id() : null,
                'Stopped by user.',
            );
            $this->projectRun->refresh();
            $this->skipRender();

            return [
                'success' => true,
                'message' => 'Đã yêu cầu dừng. Run → stopping → cancelled (không map completed).',
                'cancelled_steps' => 0,
                'status' => (string) $this->projectRun->status,
            ];
        }

        $cancelled = 0;
        try {
            $cancelled = app(SeoProjectWorkflowStepRetryService::class)
                ->cancelAllActiveSteps($this->projectRun);
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'seo.project_run.force_stop',
                'run_id' => (int) $this->projectRun->id,
            ]);
        }

        app(SeoProjectWorkflowRunService::class)->markRunCompletedQuietly($this->projectRun);
        $this->projectRun->refresh();
        $this->skipRender();

        return [
            'success' => true,
            'message' => 'Đã dừng run. F5 sẽ không tự chạy lại.',
            'cancelled_steps' => $cancelled,
        ];
    }

    /**
     * Read-only progress for PHP engine UI poll — never dispatches execution.
     *
     * @return array{success: bool, stats: array<string, int|string>, status: string}
     */
    public function pollRunProgress(): array
    {
        if ($this->projectRun === null) {
            $this->skipRender();

            return [
                'success' => false,
                'stats' => $this->getRunStatsPayload(),
                'status' => '',
            ];
        }

        $this->projectRun->refresh();
        $this->skipRender();

        return [
            'success' => true,
            'stats' => $this->getRunStatsPayload(),
            'status' => (string) ($this->projectRun->status ?? ''),
            'engineUiRunning' => in_array((string) ($this->projectRun->status ?? ''), [
                SeoProjectRun::STATUS_RUNNING,
                SeoProjectRun::STATUS_STOPPING,
            ], true),
            'orchestration' => ContentProjectRunEngineFeature::orchestrationFor($this->projectRun),
        ];
    }

    /**
     * Block legacy queue start/complete on PHP-orchestrated runs (always).
     */
    private function denyLegacyOrchestrationAction(string $action): bool
    {
        if ($this->projectRun === null || ! ContentProjectRunEngineFeature::enabledFor($this->projectRun)) {
            return false;
        }

        $this->logLegacyActionBlocked($action);
        $this->skipRender();

        return true;
    }

    /**
     * Manual article/step retry: blocked while PHP run active; allowed when terminal.
     */
    private function shouldBlockPhpEngineArticleMutation(string $action): bool
    {
        if ($this->projectRun === null || ! ContentProjectRunEngineFeature::enabledFor($this->projectRun)) {
            return false;
        }

        $status = (string) $this->projectRun->status;
        $terminal = in_array($status, [
            SeoProjectRun::STATUS_COMPLETED,
            SeoProjectRun::STATUS_CANCELLED,
            SeoProjectRun::STATUS_FAILED,
        ], true);

        if ($terminal) {
            return false;
        }

        $this->logLegacyActionBlocked($action);

        return true;
    }

    private function logLegacyActionBlocked(string $action): void
    {
        if ($this->projectRun === null) {
            return;
        }

        RuntimeLogger::warning('content_project_run.legacy_action_blocked', [
            'run_id' => (int) $this->projectRun->id,
            'action' => $action,
            'orchestration' => ContentProjectRunEngineFeature::orchestrationFor($this->projectRun),
            'status' => (string) $this->projectRun->status,
            'user_id' => auth()->id() !== null ? (int) auth()->id() : null,
            'caller' => 'ViewSeoProjectRun',
        ]);
    }

    private function reloadCurrentRunPage(): void
    {
        if ($this->projectRun === null) {
            return;
        }

        $this->redirect(
            SeoProjectResource::getUrl('view-run', ['run' => $this->projectRun]),
            navigate: false,
        );
    }

    public function markItemFixed(int $taskId, int $articleId): void
    {
        if ($this->projectRun === null) {
            return;
        }

        try {
            app(SeoProjectWorkflowRunService::class)->markTaskFixed(
                $this->projectRun,
                $taskId,
                $articleId,
            );
            $this->projectRun->refresh();

            Notification::make()
                ->title('Đã đánh dấu bài viết OK')
                ->body('Hạng mục được ghi nhận là đã sửa lỗi thủ công.')
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Không thể đánh dấu đã fix')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @deprecated Per-item archive disabled — use project-level archive.
     */
    public function archiveItem(int $taskId): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.projects.archive_item_disabled_title'))
            ->body(__('seo-content-ai::filament.projects.archive_item_disabled_body'))
            ->danger()
            ->send();

        $this->skipRender();
    }

    /**
     * Detach task khỏi Content Project active — kể cả soft-delete chưa có archived_at
     * (data kẹt trước khi Complete tự detach).
     */
    private function detachStuckProjectTask(
        ?SeoProjectTask $task,
        \App\Addons\SeoContentAi\Models\SeoProject $project,
        int $articleId,
        int $userId,
    ): void {
        $lifecycle = app(SeoProjectTaskLifecycleService::class);

        if ($task instanceof SeoProjectTask) {
            if ($task->archived_at !== null) {
                return;
            }

            if ($task->trashed()) {
                $task->forceFill([
                    'status_before_archive' => $task->status_before_archive
                        ?? ((string) $task->status !== '' ? (string) $task->status : null),
                    'status' => SeoProjectTask::STATUS_ARCHIVED,
                    'archived_at' => now(),
                ])->save();

                return;
            }

            $lifecycle->archive($task, $userId, ['from_run_archive_item' => true]);

            return;
        }

        $activeTasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->where('article_id', $articleId)
            ->active()
            ->get();

        foreach ($activeTasks as $activeTask) {
            $lifecycle->archive($activeTask, $userId, ['from_run_archive_item' => true]);
        }
    }

    /**
     * Đánh dấu run item đã đẩy khỏi project khi task gốc không còn trong DB.
     */
    private function markRunItemDetached(int $taskId): void
    {
        if ($this->projectRun === null || $taskId <= 0) {
            return;
        }

        $items = SeoProjectRunItem::query()
            ->where('run_id', (int) $this->projectRun->id)
            ->where('task_id', $taskId)
            ->get();

        foreach ($items as $item) {
            $snapshot = is_array($item->input_snapshot) ? $item->input_snapshot : [];
            $snapshot['detached_from_project'] = true;
            $snapshot['detached_from_project_at'] = now()->toIso8601String();
            $item->forceFill(['input_snapshot' => $snapshot])->save();
        }
    }

    private function removeTaskFromCurrentRunItems(int $taskId): void
    {
        if ($this->projectRun === null || $taskId <= 0) {
            return;
        }

        // Chỉ áp dụng legacy JSON run — không xóa DB run item history.
        if (! app(SeoProjectRunItemsReader::class)->usesLegacyFallback($this->projectRun)) {
            return;
        }

        $items = is_array($this->projectRun->items) ? $this->projectRun->items : [];
        $filtered = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => ! is_array($item) || (int) ($item['task_id'] ?? 0) !== $taskId,
        ));

        if (count($filtered) === count($items)) {
            return;
        }

        $succeeded = collect($filtered)->where('status', 'success')->count();
        $failed = collect($filtered)->where('status', 'failed')->count();

        $this->projectRun->update([
            'items' => $filtered,
            'total' => count($filtered),
            'succeeded' => $succeeded,
            'failed' => $failed,
        ]);
        $this->projectRun->refresh();
    }

    private function syncResolvedArticleIdForRunTask(int $taskId): int
    {
        $articleId = $this->resolveArticleIdForRunTask($taskId);
        if ($articleId <= 0 || $this->projectRun === null) {
            return 0;
        }

        $this->projectRun->refresh()->loadMissing(['project.tasks']);

        $task = $this->projectRun->project?->tasks?->firstWhere('id', $taskId);
        if ($task instanceof SeoProjectTask && (int) ($task->article_id ?? 0) !== $articleId) {
            $task->article_id = $articleId;
            $task->save();
            $this->projectRun->project?->unsetRelation('tasks');
            $this->projectRun->loadMissing('project.tasks');
        }

        $reader = app(SeoProjectRunItemsReader::class);
        if (! $reader->usesLegacyFallback($this->projectRun)) {
            $updated = SeoProjectRunItem::query()
                ->where('run_id', (int) $this->projectRun->id)
                ->where('task_id', $taskId)
                ->where(function ($query) use ($articleId): void {
                    $query->whereNull('article_id')
                        ->orWhere('article_id', '!=', $articleId);
                })
                ->update(['article_id' => $articleId]);

            if ($updated > 0) {
                app(SeoProjectRunItemService::class)->mirrorJsonSafely($this->projectRun);
                $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);
            }

            return $articleId;
        }

        $items = is_array($this->projectRun->items) ? $this->projectRun->items : [];
        $changed = false;

        foreach ($items as $index => $item) {
            if (! is_array($item) || (int) ($item['task_id'] ?? 0) !== $taskId) {
                continue;
            }

            if ((int) ($item['article_id'] ?? 0) !== $articleId) {
                $items[$index]['article_id'] = $articleId;
                $changed = true;
            }

            break;
        }

        if ($changed) {
            // Legacy-only compatibility path — không dùng cho DB run items.
            $this->projectRun->update(['items' => array_values($items)]);
            $this->projectRun->refresh()->loadMissing(['project.site', 'user', 'project.tasks']);
        }

        return $articleId;
    }

    private function resolveArticleIdForRunTask(int $taskId): int
    {
        if ($taskId <= 0) {
            return 0;
        }

        // Đọc raw reader (kể cả task đã archive) — tránh getResultItems() đã filter.
        foreach (app(SeoProjectRunItemsReader::class)->forRunAsArrays($this->projectRun) as $item) {
            if ((int) ($item['task_id'] ?? 0) !== $taskId) {
                continue;
            }

            $fromItem = (int) ($item['article_id'] ?? 0);
            if ($fromItem > 0) {
                return $fromItem;
            }

            break;
        }

        $project = $this->projectRun?->project;
        if ($project === null) {
            return 0;
        }

        return (int) (SeoProjectTask::withTrashed()
            ->where('project_id', (int) $project->getKey())
            ->whereKey($taskId)
            ->value('article_id') ?? 0);
    }

    public function canArchiveRunItem(array $item): bool
    {
        return false;
    }

    public function canRetryRunItem(array $item): bool
    {
        if (! SeoAccessControl::canRetryProjectRunItem($this->projectRun?->project)) {
            return false;
        }

        if (array_key_exists('can_retry', $item)) {
            return (bool) $item['can_retry'];
        }

        return (bool) ($item['task_exists'] ?? true)
            && (int) ($item['task_id'] ?? 0) > 0;
    }

    protected function getHeaderActions(): array
    {
        $project = $this->projectRun?->project;

        return [
            Actions\Action::make('back_to_project')
                ->label(__('seo-content-ai::filament.projects.view_runs'))
                ->icon('heroicon-o-arrow-left')
                ->url(
                    $project !== null
                        ? SeoProjectResource::getRunHistoryUrl($project)
                        : SeoProjectResource::getUrl('index'),
                ),
            Actions\Action::make('back_to_list')
                ->label(__('seo-content-ai::filament.projects.back_to_projects'))
                ->color('gray')
                ->url(SeoProjectResource::getUrl('index')),
        ];
    }

    private function isImproveTaskId(int $taskId): bool
    {
        if ($taskId <= 0) {
            return false;
        }

        $project = $this->projectRun?->project;
        if ($project === null) {
            return false;
        }

        $type = $project->tasks()
            ->whereKey($taskId)
            ->value('type');

        return SeoProjectTask::isManualRunType((string) ($type ?? ''));
    }
}
