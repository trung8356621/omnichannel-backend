<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Concerns\InteractsWithContentProjectPublishingActions;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ReturnToContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueReadModel;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Carbon\Carbon;
use Filament\Actions;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Independent Publishing Queue hub — top-level page (not nested under a Content Project).
 * Optional `projectId` query param scopes to one project; without it, lists across
 * every accessible project (cross-project view, actions disabled per row).
 *
 * The nested `content-projects/{id}/publishing-queue` resource route redirects here (compat).
 */
final class PublishingQueueHub extends SeoPanelPage
{
    use InteractsWithContentProjectPublishingActions;

    protected static ?string $slug = 'publishing-queue';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'seo-content-ai::filament.pages.publishing-queue-hub';

    #[Url]
    public ?int $projectId = null;

    public ?SeoProject $project = null;

    public string $search = '';

    public string $stateFilter = '';

    /** @var list<int> */
    public array $selectedTaskIds = [];

    public bool $bulkRunning = false;

    public string $autoMode = 'project_month';

    public string $autoStartAt = '';

    public int $autoIntervalMinutes = 15;

    public int $autoPerDay = 3;

    public string $autoDayStart = '09:00';

    public string $autoDayEnd = '17:00';

    public int $quickDays = 3;

    public string $quickStartTime = '08:00';

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.projects.publishing_queue_nav_label');
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canManageContentProjectWorkflow();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $this->resolveProject();
        if ($this->autoStartAt === '') {
            $this->autoStartAt = now()->addHour()->format('Y-m-d\TH:i');
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->project instanceof SeoProject
            ? __('seo-content-ai::filament.projects.publishing_queue_title', ['name' => (string) $this->project->name])
            : __('seo-content-ai::filament.projects.publishing_queue_hub_title');
    }

    public function updatedProjectId(): void
    {
        $this->resolveProject();
        $this->clearFilters();
    }

    private function resolveProject(): void
    {
        $this->project = null;
        if ($this->projectId === null || $this->projectId <= 0) {
            return;
        }

        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()->find($this->projectId);
        if (! $project instanceof SeoProject || ! SeoAccessControl::canAccessSite((int) ($project->site_id ?? 0))) {
            $this->projectId = null;

            return;
        }

        $this->project = $project;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function getSelectableProjectsProperty(): array
    {
        return SeoProjectResource::getRecordRouteBindingEloquentQuery()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (SeoProject $p): array => ['id' => (int) $p->getKey(), 'name' => (string) $p->name])
            ->all();
    }

    /**
     * @return array{stats: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function getQueuePayloadProperty(): array
    {
        return app(ContentProjectPublishingQueueReadModel::class)->forHub(
            $this->project instanceof SeoProject ? (int) $this->project->getKey() : null,
            [
                'search' => $this->search,
                'state' => $this->stateFilter,
            ],
        );
    }

    public function applyStateFilter(string $state): void
    {
        $this->stateFilter = $state === $this->stateFilter ? '' : $state;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->stateFilter = '';
        $this->selectedTaskIds = [];
    }

    public function clearSelection(): void
    {
        $this->selectedTaskIds = [];
    }

    public function selectPage(): void
    {
        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $this->selectedTaskIds,
        ), static fn (int $id): bool => $id > 0));

        foreach ($this->queuePayload['rows'] ?? [] as $row) {
            $id = (int) ($row['task_id'] ?? 0);
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $this->selectedTaskIds = $ids;
    }

    /**
     * @return list<int>
     */
    protected function selectedItemIds(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $this->selectedTaskIds,
        ), static fn (int $id): bool => $id > 0));
    }

    public function returnOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new ReturnToContentProjectCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'return_to_content_project');
    }

    public function bulkReturn(): void
    {
        $this->dispatchPublishingCommand(new ReturnToContentProjectCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'return_to_content_project');
    }

    public function scheduleOneAt(int $taskId, string $at): void
    {
        $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
            Carbon::parse($at),
        ), 'schedule');
    }

    public function runProjectMonthAutoSchedule(): void
    {
        $this->dispatchPublishingCommand(new AutoScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
            [
                'mode' => 'project_month',
                'day_start' => $this->autoDayStart,
                'day_end' => $this->autoDayEnd,
            ],
        ), 'auto_schedule');
    }

    public function runQuickSchedule(): void
    {
        $this->dispatchPublishingCommand(new AutoScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
            [
                'mode' => 'quick',
                'days' => max(1, $this->quickDays),
                'start_time' => $this->quickStartTime,
                'end_time' => $this->autoDayEnd,
            ],
        ), 'auto_schedule');
    }

    protected function getHeaderActions(): array
    {
        if (! $this->project instanceof SeoProject) {
            return [];
        }

        return [
            Actions\Action::make('back_to_project')
                ->label(__('seo-content-ai::filament.projects.ops_items_heading'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(SeoProjectResource::getUrl('view', ['record' => $this->project])),
        ];
    }

    protected function requireProject(): SeoProject
    {
        if (! $this->project instanceof SeoProject) {
            abort(404);
        }

        return $this->project;
    }
}
