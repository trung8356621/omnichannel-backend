<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Concerns;

use App\Addons\SeoContentAi\Models\SeoProject;
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
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Throwable;

/**
 * Publishing bulk/item actions — shared by canonical project page (ex-Publishing Queue).
 *
 * Host component MUST declare:
 * - public bool $bulkRunning
 * - public string $autoMode, $autoStartAt, $autoDayStart, $autoDayEnd
 * - public int $autoIntervalMinutes, $autoPerDay
 * (Livewire không luôn expose property khai báo trong trait.)
 */
trait InteractsWithContentProjectPublishingActions
{
    abstract protected function requireProject(): SeoProject;

    /** @return list<int> */
    abstract protected function selectedItemIds(): array;

    abstract public function clearSelection(): void;

    public function bulkSchedule(?string $at = null): void
    {
        $when = $at !== null && $at !== ''
            ? Carbon::parse($at)
            : now()->addHour();

        $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
            $when,
        ), 'schedule');
    }

    public function bulkUnschedule(): void
    {
        $this->dispatchPublishingCommand(new UnscheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'unschedule');
    }

    public function bulkPublishNow(): void
    {
        $this->dispatchPublishingCommand(new PublishProjectItemsNowCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'publish_now');
    }

    public function bulkRetryPublish(): void
    {
        $this->dispatchPublishingCommand(new RetryProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'retry');
    }

    public function bulkMoveTime(string $at): void
    {
        $this->dispatchPublishingCommand(new MoveProjectItemScheduleCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
            Carbon::parse($at),
        ), 'move_time');
    }

    public function bulkClearSchedule(): void
    {
        $this->dispatchPublishingCommand(new UnscheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'clear_schedule');
    }

    public function bulkSkipPublish(): void
    {
        $this->dispatchPublishingCommand(new SkipProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'skip');
    }

    public function bulkCancelPublish(): void
    {
        $this->dispatchPublishingCommand(new CancelProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
        ), 'cancel');
    }

    public function retryPublishOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new RetryProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'retry');
    }

    public function skipPublishOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new SkipProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'skip');
    }

    public function cancelPublishOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new CancelProjectItemPublishingCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'cancel');
    }

    public function scheduleOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new ScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
            now()->addHour(),
        ), 'schedule');
    }

    public function unscheduleOne(int $taskId): void
    {
        $this->dispatchPublishingCommand(new UnscheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'unschedule');
    }

    public function publishOneNow(int $taskId): void
    {
        $this->dispatchPublishingCommand(new PublishProjectItemsNowCommand(
            (int) $this->requireProject()->getKey(),
            [$taskId],
        ), 'publish_now');
    }

    public function runAutoSchedule(): void
    {
        $this->dispatchPublishingCommand(new AutoScheduleProjectItemsCommand(
            (int) $this->requireProject()->getKey(),
            $this->selectedItemIds(),
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

    private function dispatchPublishingCommand(object $command, string $op): void
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canMutateContentProjects(), 403);

        $embedded = property_exists($command, 'itemRefs') && is_array($command->itemRefs)
            ? array_values(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                $command->itemRefs,
            ), static fn (int $id): bool => $id > 0))
            : [];

        if ($embedded === []) {
            app(ContentProjectActionResultNotifier::class)->send(
                ContentProjectActionResult::fail(
                    'validation.failed',
                    (string) __('seo-content-ai::filament.projects.queue_select_required'),
                    (int) $this->requireProject()->getKey(),
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
                    (int) ($this->requireProject()->site_id ?? 0) ?: null,
                ),
            );

            app(ContentProjectActionResultNotifier::class)->send($result);

            if ($result->success) {
                $this->clearSelection();
            }
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.items.publishing.'.$op,
                'project_id' => (int) $this->requireProject()->getKey(),
            ]);
            app(ContentProjectActionResultNotifier::class)->send(
                ContentProjectActionResult::fail(
                    'failed',
                    $e->getMessage(),
                    (int) $this->requireProject()->getKey(),
                ),
            );
        } finally {
            $this->bulkRunning = false;
        }
    }
}
