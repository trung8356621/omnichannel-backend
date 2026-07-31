<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Concerns\InteractsWithContentProjectPublishingActions;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectRunItem;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\StartReviewCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectCommandBus;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentWorkspaceDeepLink;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectItemGenerationClassifier;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectItemOperationsReadModel;
use App\Addons\SeoContentAi\Services\ContentProjectBulkRerunService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\WithPagination;
use Throwable;

/**
 * Canonical project workspace — one items table for generation/review/publishing.
 */
final class ViewSeoProject extends Page
{
    use InteractsWithContentProjectPublishingActions;
    use WithPagination;

    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.view-seo-project-operations';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $record = 0;

    public ?SeoProject $project = null;

    /** @var list<int> */
    public array $selectedTaskIds = [];

    public bool $settingsOpen = false;

    public bool $executionDetailsOpen = false;

    public ?int $executionDetailsTaskId = null;

    public string $search = '';

    public string $typeFilter = '';

    public string $generationFilter = '';

    public string $lifecycleFilter = '';

    public string $queueFilter = '';

    public string $scheduledFilter = '';

    public bool $failedOnly = false;

    /** Livewire public — must live on component, not trait. */
    public bool $bulkRunning = false;

    public string $autoMode = 'interval';

    public string $autoStartAt = '';

    public int $autoIntervalMinutes = 15;

    public int $autoPerDay = 3;

    public string $autoDayStart = '09:00';

    public string $autoDayEnd = '17:00';

    /** @var array<string, mixed> */
    protected $queryString = [
        'search' => ['except' => ''],
        'typeFilter' => ['except' => '', 'as' => 'type'],
        'generationFilter' => ['except' => '', 'as' => 'generation'],
        'lifecycleFilter' => ['except' => '', 'as' => 'lifecycle'],
        'queueFilter' => ['except' => '', 'as' => 'queue'],
        'scheduledFilter' => ['except' => '', 'as' => 'scheduled'],
        'failedOnly' => ['except' => false, 'as' => 'failed'],
    ];

    public function mount(int|string $record): void
    {
        $this->record = $record;
        $this->project = $this->resolveProject($record);
        abort_unless(SeoProjectResource::canView($this->project), 403);

        if ($this->project->isArchive() || $this->project->isProjectArchived()) {
            $this->redirect(SeoProjectResource::getUrl('index'));
        }

        if ($this->autoStartAt === '') {
            $this->autoStartAt = now()->addHour()->format('Y-m-d\TH:i');
        }
    }

    public function getTitle(): string|Htmlable
    {
        return (string) ($this->project?->name ?? __('seo-content-ai::filament.projects.navigation'));
    }

    public function getHeading(): string|Htmlable
    {
        return (string) ($this->project?->name ?? __('seo-content-ai::filament.projects.navigation'));
    }

    public function getSubheading(): string|Htmlable|null
    {
        $project = $this->project;
        if (! $project instanceof SeoProject) {
            return null;
        }

        return implode(' · ', [
            (string) ($project->site?->domain ?? '—'),
            (string) ($project->user?->name ?? '—'),
            (string) ($project->month?->format('m/Y') ?? '—'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOperationsPayloadProperty(): array
    {
        return app(ContentProjectItemOperationsReadModel::class)->forProject(
            $this->requireProject(),
            [
                'search' => $this->search,
                'type' => $this->typeFilter,
                'generation' => $this->generationFilter,
                'lifecycle' => $this->lifecycleFilter,
                'queue' => $this->queueFilter,
                'scheduled' => $this->scheduledFilter,
                'failed_only' => $this->failedOnly,
                'page' => $this->getPage(),
            ],
        );
    }

    public function getActiveSummaryCardProperty(): string
    {
        if ($this->failedOnly) {
            return 'failed';
        }
        if ($this->generationFilter === 'pending') {
            return 'pending';
        }
        if ($this->generationFilter === 'running') {
            return 'running';
        }
        if ($this->lifecycleFilter === 'review') {
            return 'review';
        }
        if ($this->lifecycleFilter === 'approved') {
            return 'approved';
        }
        if ($this->lifecycleFilter === 'waiting_publish') {
            return 'scheduled';
        }
        if ($this->lifecycleFilter === 'published') {
            return 'published';
        }
        if (! $this->hasActiveFilters) {
            return 'total';
        }

        return '';
    }

    public function getHasActiveFiltersProperty(): bool
    {
        return $this->search !== ''
            || $this->typeFilter !== ''
            || $this->generationFilter !== ''
            || $this->lifecycleFilter !== ''
            || $this->queueFilter !== ''
            || $this->scheduledFilter !== ''
            || $this->failedOnly;
    }

    protected function getHeaderActions(): array
    {
        $project = $this->requireProject();

        return [
            Actions\Action::make('open_in_agent')
                ->label(__('seo-content-ai::filament.agent_workspace.open_workspace'))
                ->icon('heroicon-o-cpu-chip')
                ->color('gray')
                ->url(fn (): string => AgentWorkspaceDeepLink::url([
                    'project_ref' => ContentProjectPublicRef::project((int) $project->getKey()),
                ])),
            SeoProjectResource::makeGeneratePendingItemsAction($project),
            Actions\Action::make('edit_project')
                ->label(__('seo-content-ai::filament.projects.edit_project'))
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->url(fn (): string => SeoProjectResource::getUrl('edit', ['record' => $project])),
            Actions\Action::make('toggle_settings')
                ->label(__('seo-content-ai::filament.projects.project_settings_toggle'))
                ->icon('heroicon-o-information-circle')
                ->color('gray')
                ->action(fn () => $this->settingsOpen = ! $this->settingsOpen),
            Actions\ActionGroup::make([
                SeoProjectResource::makeDevTestGeneratePendingItemsAction($project),
            ])
                ->label('More')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button()
                ->visible(fn (): bool => SeoProjectResource::allowsDevTestGenerateUi()),
        ];
    }

    public function applySummaryFilter(string $card): void
    {
        $this->resetPage();
        $this->clearFilters(false);

        match ($card) {
            'pending' => $this->generationFilter = 'pending',
            'running' => $this->generationFilter = 'running',
            'failed' => $this->failedOnly = true,
            'review' => $this->lifecycleFilter = 'review',
            'approved' => $this->lifecycleFilter = 'approved',
            'scheduled' => $this->lifecycleFilter = 'waiting_publish',
            'published' => $this->lifecycleFilter = 'published',
            default => null,
        };
    }

    public function clearFilters(bool $resetPage = true): void
    {
        $this->search = '';
        $this->typeFilter = '';
        $this->generationFilter = '';
        $this->lifecycleFilter = '';
        $this->queueFilter = '';
        $this->scheduledFilter = '';
        $this->failedOnly = false;
        $this->clearSelection();
        if ($resetPage) {
            $this->resetPage();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedGenerationFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedLifecycleFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedQueueFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedScheduledFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedFailedOnly(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedSelectedTaskIds(): void
    {
        $this->selectedTaskIds = $this->normalizeSelectedIds($this->selectedTaskIds);
    }

    public function toggleSelect(int $taskId): void
    {
        $ids = $this->normalizeSelectedIds($this->selectedTaskIds);
        if (in_array($taskId, $ids, true)) {
            $this->selectedTaskIds = array_values(array_filter(
                $ids,
                static fn (int $id): bool => $id !== $taskId,
            ));

            return;
        }

        $ids[] = $taskId;
        $this->selectedTaskIds = $ids;
    }

    public function selectPage(): void
    {
        $ids = $this->normalizeSelectedIds($this->selectedTaskIds);
        foreach ($this->operationsPayload['rows'] ?? [] as $row) {
            $id = (int) ($row['task_id'] ?? 0);
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $this->selectedTaskIds = $ids;
    }

    public function clearSelection(): void
    {
        $this->selectedTaskIds = [];
    }

    public function getHasSelectionProperty(): bool
    {
        return count($this->normalizeSelectedIds($this->selectedTaskIds)) > 0;
    }

    public function getSelectedCountProperty(): int
    {
        return count($this->normalizeSelectedIds($this->selectedTaskIds));
    }

    /** @return list<int> */
    protected function selectedItemIds(): array
    {
        return $this->normalizeSelectedIds($this->selectedTaskIds);
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function normalizeSelectedIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    public function archiveSelected(): void
    {
        $this->dispatchArchive($this->selectedItemIds());
    }

    public function archiveOne(int $taskId): void
    {
        $this->dispatchArchive([$taskId]);
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function dispatchArchive(array $taskIds): void
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canMutateContentProjects(), 403);

        $ids = $this->normalizeSelectedIds($taskIds);
        if ($ids === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.queue_select_required'))
                ->warning()
                ->send();

            return;
        }

        $project = $this->requireProject();
        $this->bulkRunning = true;

        try {
            $result = app(ContentProjectCommandBus::class)->dispatch(
                new ArchiveProjectItemsCommand(
                    (int) $project->getKey(),
                    $ids,
                ),
                ActorContext::user(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    (int) ($project->site_id ?? 0) ?: null,
                ),
            );

            Notification::make()
                ->title($result->success
                    ? __('seo-content-ai::filament.projects.archive_item_completed')
                    : __('seo-content-ai::filament.projects.archive_failed'))
                ->body($result->success
                    ? __('seo-content-ai::filament.projects.archive_item_completed_body', [
                        'archived' => (int) ($result->metadata['affected_count'] ?? count($ids)),
                    ])
                    : $result->message)
                ->{$result->success ? 'success' : 'danger'}()
                ->send();

            if ($result->success) {
                $archived = array_flip($ids);
                $this->selectedTaskIds = array_values(array_filter(
                    $this->normalizeSelectedIds($this->selectedTaskIds),
                    static fn (int $id): bool => ! isset($archived[$id]),
                ));
            }
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.items.archive',
                'project_id' => (int) $project->getKey(),
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->bulkRunning = false;
        }
    }

    public function openExecutionDetails(int $taskId): void
    {
        $this->executionDetailsTaskId = $taskId;
        $this->executionDetailsOpen = true;
    }

    public function closeExecutionDetails(): void
    {
        $this->executionDetailsOpen = false;
        $this->executionDetailsTaskId = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getExecutionDetailsRowsProperty(): array
    {
        if ($this->executionDetailsTaskId === null) {
            return [];
        }

        return SeoProjectRunItem::query()
            ->where('task_id', $this->executionDetailsTaskId)
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'run_id', 'status', 'action', 'error_message', 'started_at', 'finished_at'])
            ->map(static fn ($item): array => [
                'id' => (int) $item->id,
                'run_id' => (int) $item->run_id,
                'status' => (string) $item->status,
                'action' => (string) ($item->action ?? '—'),
                'error' => (string) ($item->error_message ?? ''),
                'started_at' => $item->started_at?->format('d/m/Y H:i'),
                'finished_at' => $item->finished_at?->format('d/m/Y H:i'),
            ])
            ->all();
    }

    public function generateSelected(): void
    {
        $this->dispatchGenerate($this->selectedTaskIds);
    }

    public function generateOne(int $taskId): void
    {
        $this->dispatchGenerate([$taskId]);
    }

    public function rerunOne(int $taskId): void
    {
        $project = $this->requireProject();
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $this->dispatchBus(new RerunProjectItemsCommand(
            (int) $project->id,
            [(int) $taskId],
            SeoProjectRun::MODE_FULL,
        ));
        // Force Livewire re-read so Running / Failed badges update after engine kick.
        $this->resetPage();
    }

    public function bulkRegenOutline(): void
    {
        $this->dispatchBulkStep($this->selectedTaskIds, ContentProjectBulkRerunService::ACTION_OUTLINE);
    }

    public function bulkRegenArticle(): void
    {
        $this->dispatchBulkStep($this->selectedTaskIds, ContentProjectBulkRerunService::ACTION_ARTICLE);
    }

    public function regenOutline(int $taskId): void
    {
        $this->dispatchBulkStep([$taskId], ContentProjectBulkRerunService::ACTION_OUTLINE);
    }

    public function regenArticle(int $taskId): void
    {
        $this->dispatchBulkStep([$taskId], ContentProjectBulkRerunService::ACTION_ARTICLE);
    }

    public function startReviewSelected(): void
    {
        $this->dispatchBus(new StartReviewCommand((int) $this->requireProject()->id, $this->selectedTaskIds));
    }

    public function startReviewOne(int $taskId): void
    {
        $this->dispatchBus(new StartReviewCommand((int) $this->requireProject()->id, [$taskId]));
    }

    public function approveSelected(): void
    {
        $this->dispatchBus(new ApproveProjectItemsCommand((int) $this->requireProject()->id, $this->selectedTaskIds));
    }

    public function approveOne(int $taskId): void
    {
        $this->dispatchBus(new ApproveProjectItemsCommand((int) $this->requireProject()->id, [$taskId]));
    }

    /**
     * @param  list<int>  $taskIds
     * @return array{valid: int, skipped: list<array{task_id: int, reason: string}>}
     */
    public function previewBulkGenerate(array $taskIds = []): array
    {
        $ids = $taskIds !== [] ? $taskIds : $this->selectedTaskIds;
        $preview = app(ContentProjectItemGenerationClassifier::class)->preview($this->requireProject());
        $allowed = array_flip($preview->runnableTaskIds());
        $valid = 0;
        $skipped = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($allowed[$id])) {
                $valid++;
                continue;
            }
            $reason = 'not_eligible';
            foreach ($preview->decisions as $d) {
                if ($d->taskId === $id) {
                    $reason = $d->reason;
                    break;
                }
            }
            $skipped[] = ['task_id' => $id, 'reason' => $reason];
        }

        return ['valid' => $valid, 'skipped' => $skipped];
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function dispatchGenerate(array $taskIds): void
    {
        $project = $this->requireProject();
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $preview = $this->previewBulkGenerate($taskIds);
        if ($preview['valid'] <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_failed'))
                ->body(__('seo-content-ai::filament.projects.run_items_empty'))
                ->danger()
                ->send();

            return;
        }

        $skippedMap = [];
        foreach ($preview['skipped'] as $row) {
            $skippedMap[(int) $row['task_id']] = true;
        }
        $eligible = array_values(array_filter(
            $taskIds,
            static fn (int $id): bool => ! isset($skippedMap[$id]),
        ));

        try {
            SeoProjectResource::startGeneratePendingItems(
                $project,
                SeoProjectRun::MODE_FULL,
                [
                    'task_ids' => $eligible,
                    'use_php_engine' => true,
                ],
            );
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_started'))
                ->body(__('seo-content-ai::filament.projects.generate_pending_started_body'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['endpoint' => 'content_project.operations.generate_engine']);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function dispatchBulkStep(array $taskIds, string $action): void
    {
        $project = $this->requireProject();
        $filtered = [];
        foreach ($taskIds as $id) {
            $task = SeoProjectTask::query()->find((int) $id);
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            if (SeoProjectTask::normalizeType($task->type) === SeoProjectTask::TYPE_IMPROVE) {
                continue;
            }
            $filtered[] = (int) $task->id;
        }

        if ($filtered === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.item_improve_blocked'))
                ->warning()
                ->send();

            return;
        }

        $run = SeoProjectRun::query()
            ->where('project_id', (int) $project->id)
            ->orderByDesc('id')
            ->first();

        if (! $run instanceof SeoProjectRun) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.item_regen_needs_execution'))
                ->warning()
                ->send();

            return;
        }

        try {
            $result = app(ContentProjectBulkRerunService::class)
                ->execute($run, $project, $filtered, $action, true);
            Notification::make()
                ->title($result['success'] ? 'OK' : 'Failed')
                ->body((string) $result['message'])
                ->{$result['success'] ? 'success' : 'danger'}()
                ->send();
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['endpoint' => 'content_project.operations.bulk_step']);
            Notification::make()->title('Rerun failed')->body($e->getMessage())->danger()->send();
        }
    }

    private function dispatchBus(object $command): void
    {
        $project = $this->requireProject();
        $result = app(ContentProjectCommandBus::class)->dispatch(
            $command,
            ActorContext::user(
                auth()->id() !== null ? (int) auth()->id() : null,
                (int) ($project->site_id ?? 0) ?: null,
            ),
        );

        Notification::make()
            ->title($result->success ? 'OK' : 'Failed')
            ->body($result->message)
            ->{$result->success ? 'success' : 'danger'}()
            ->send();
    }

    private function resolveProject(int|string $key): SeoProject
    {
        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()
            ->with(['user', 'site'])
            ->find($key);
        abort_unless($project instanceof SeoProject, 404);

        return $project;
    }

    private function requireProject(): SeoProject
    {
        if (! $this->project instanceof SeoProject) {
            $this->project = $this->resolveProject($this->record);
        }

        return $this->project;
    }
}
