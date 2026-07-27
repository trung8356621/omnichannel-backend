<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\MoveProjectItemScheduleCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\SkipProjectItemPublishingCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UnscheduleProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectCommandBus;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectDashboardStatsService;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectTimelineService;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectLifecycle;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\WithPagination;
use Throwable;

/**
 * Publishing Queue + dashboard stats + timeline cho một Content Project.
 */
final class ContentProjectPublishingQueue extends Page
{
    use WithPagination;

    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.content-project-publishing-queue';

    protected static bool $shouldRegisterNavigation = false;

    public SeoProject $record;

    /** @var list<int> */
    public array $selectedTaskIds = [];

    public string $statusFilter = '';

    public string $search = '';

    public string $autoMode = 'interval';

    public string $autoStartAt = '';

    public int $autoIntervalMinutes = 15;

    public int $autoPerDay = 3;

    public string $autoDayStart = '09:00';

    public string $autoDayEnd = '17:00';

    public bool $bulkRunning = false;

    /** @var array<string, mixed> */
    protected $queryString = [
        'statusFilter' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(SeoAccessControl::canAccessSite((int) ($this->record->site_id ?? 0)), 403);

        if ($this->autoStartAt === '') {
            $this->autoStartAt = now()->addHour()->format('Y-m-d\TH:i');
        }
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.projects.publishing_queue_title', [
            'name' => (string) $this->record->name,
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function getStatsProperty(): array
    {
        return app(ContentProjectDashboardStatsService::class)->forProject($this->record);
    }

    /**
     * @return list<array{key: string, label: string, at: string|null, done: bool}>
     */
    public function getTimelineProperty(): array
    {
        return app(ContentProjectTimelineService::class)->forProject($this->record);
    }

    public function getQueueRowsProperty(): LengthAwarePaginator
    {
        $query = SeoProjectTask::query()
            ->where('project_id', (int) $this->record->getKey())
            ->active()
            ->where('article_id', '>', 0)
            ->with(['article:id,title,slug,status,is_reviewed,published_at,wp_post_id'])
            ->orderByRaw('scheduled_publish_at IS NULL')
            ->orderBy('scheduled_publish_at')
            ->orderBy('id');

        if ($this->statusFilter !== '') {
            $query->where('publish_queue_status', $this->statusFilter);
        }

        if (trim($this->search) !== '') {
            $needle = '%'.trim($this->search).'%';
            $query->whereHas('article', static function ($q) use ($needle): void {
                $q->where('title', 'like', $needle)
                    ->orWhere('slug', 'like', $needle);
            });
        }

        return $query->paginate(30);
    }

    public function toggleSelect(int $taskId): void
    {
        if (in_array($taskId, $this->selectedTaskIds, true)) {
            $this->selectedTaskIds = array_values(array_filter(
                $this->selectedTaskIds,
                static fn (int $id): bool => $id !== $taskId,
            ));

            return;
        }

        $this->selectedTaskIds[] = $taskId;
    }

    public function selectPage(): void
    {
        foreach ($this->queueRows->items() as $task) {
            if ($task instanceof SeoProjectTask && ! in_array((int) $task->id, $this->selectedTaskIds, true)) {
                $this->selectedTaskIds[] = (int) $task->id;
            }
        }
    }

    public function clearSelection(): void
    {
        $this->selectedTaskIds = [];
    }

    public function bulkSchedule(?string $at = null): void
    {
        $when = $at !== null && $at !== ''
            ? Carbon::parse($at)
            : now()->addHour();

        $this->dispatchCommand(new ScheduleProjectItemsCommand(
            (int) $this->record->getKey(),
            $this->selectedTaskIds,
            $when,
        ), 'schedule');
    }

    public function bulkUnschedule(): void
    {
        $this->dispatchCommand(new UnscheduleProjectItemsCommand(
            (int) $this->record->getKey(),
            $this->selectedTaskIds,
        ), 'unschedule');
    }

    public function bulkPublishNow(): void
    {
        $this->dispatchCommand(new PublishProjectItemsNowCommand(
            (int) $this->record->getKey(),
            $this->selectedTaskIds,
        ), 'publish_now');
    }

    public function bulkRetry(): void
    {
        $this->dispatchCommand(new RetryProjectItemPublishingCommand(
            (int) $this->record->getKey(),
            $this->selectedTaskIds,
        ), 'retry');
    }

    public function bulkMoveTime(string $at): void
    {
        $this->dispatchCommand(new MoveProjectItemScheduleCommand(
            (int) $this->record->getKey(),
            $this->selectedTaskIds,
            Carbon::parse($at),
        ), 'move_time');
    }

    public function bulkClearSchedule(): void
    {
        $this->dispatchCommand(new UnscheduleProjectItemsCommand(
            (int) $this->record->getKey(),
            $this->selectedTaskIds,
        ), 'clear_schedule');
    }

    public function retryOne(int $taskId): void
    {
        $this->selectedTaskIds = [$taskId];
        $this->bulkRetry();
    }

    public function skipOne(int $taskId): void
    {
        $this->dispatchCommand(new SkipProjectItemPublishingCommand(
            (int) $this->record->getKey(),
            [$taskId],
        ), 'skip');
    }

    public function cancelOne(int $taskId): void
    {
        $this->dispatchCommand(new CancelProjectItemPublishingCommand(
            (int) $this->record->getKey(),
            [$taskId],
        ), 'cancel');
    }

    public function runAutoSchedule(): void
    {
        $this->dispatchCommand(new AutoScheduleProjectItemsCommand(
            (int) $this->record->getKey(),
            $this->selectedTaskIds,
            [
                'mode' => $this->autoMode,
                'start_at' => $this->autoStartAt,
                'interval_minutes' => $this->autoIntervalMinutes,
                'per_day' => $this->autoPerDay,
                'day_start' => $this->autoDayStart,
                'day_end' => $this->autoDayEnd,
            ],
        ), 'auto_schedule');
    }

    public function resolvePhaseLabel(SeoProjectTask $task): string
    {
        $phase = app(ContentProjectLifecycle::class)->resolvePhase($task);

        return __('seo-content-ai::filament.projects.lifecycle_'.$phase->value);
    }

    /**
     * @param  \App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand  $command
     */
    private function dispatchCommand(object $command, string $op): void
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canMutateContentProjects(), 403);

        if ($this->selectedTaskIds === [] && ! in_array($op, ['skip', 'cancel'], true)) {
            app(ContentProjectActionResultNotifier::class)->send(
                ContentProjectActionResult::fail(
                    'validation.failed',
                    (string) __('seo-content-ai::filament.projects.queue_select_required'),
                    (int) $this->record->getKey(),
                ),
            );

            return;
        }

        $this->bulkRunning = true;

        try {
            $result = app(ContentProjectCommandBus::class)->dispatch(
                $command,
                ActorContext::user(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    (int) ($this->record->site_id ?? 0) ?: null,
                ),
            );

            app(ContentProjectActionResultNotifier::class)->send($result);

            if ($result->success) {
                $this->clearSelection();
            }
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.publishing_queue.'.$op,
                'project_id' => (int) $this->record->getKey(),
            ]);
            app(ContentProjectActionResultNotifier::class)->send(
                ContentProjectActionResult::fail(
                    'failed',
                    $e->getMessage(),
                    (int) $this->record->getKey(),
                ),
            );
        } finally {
            $this->bulkRunning = false;
        }
    }

    private function resolveRecord(int|string $key): SeoProject
    {
        $project = SeoProject::query()->find($key);
        abort_if(! $project instanceof SeoProject, 404);

        return $project;
    }
}
