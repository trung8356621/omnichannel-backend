<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoProjectMergeService
{
    /**
     * @return Collection<int, SeoProject>
     */
    public function availableTargets(SeoProject $source): Collection
    {
        return SeoProject::query()
            ->whereKeyNot($source->getKey())
            ->where('site_id', $source->site_id)
            ->whereDate('month', '<', $source->monthCarbon()->format('Y-m-d'))
            ->withCount('tasks')
            ->orderByDesc('month')
            ->get()
            ->filter(
                static fn (SeoProject $project): bool => (int) $project->tasks_count < $project->maxTasksAllowed(),
            )
            ->values();
    }

    /**
     * @return array{moved: int, remaining: int, target_total: int, target_capacity: int}
     */
    public function mergeCompletedTasks(SeoProject $source, SeoProject $target): array
    {
        if ($source->getConnectionName() !== $target->getConnectionName()) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.merge_invalid_target'));
        }

        return DB::connection($source->getConnectionName())->transaction(
            function () use ($source, $target): array {
                $projects = SeoProject::query()
                    ->whereIn('id', [(int) $source->getKey(), (int) $target->getKey()])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                /** @var SeoProject|null $lockedSource */
                $lockedSource = $projects->get((int) $source->getKey());
                /** @var SeoProject|null $lockedTarget */
                $lockedTarget = $projects->get((int) $target->getKey());

                $this->assertProjectsCanMerge($lockedSource, $lockedTarget);

                $targetTaskCount = $lockedTarget->tasks()->count();
                $availableSlots = max(0, $lockedTarget->maxTasksAllowed() - $targetTaskCount);
                if ($availableSlots === 0) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.merge_target_full'));
                }

                $tasks = $lockedSource->tasks()
                    ->where('status', SeoProjectTask::STATUS_COMPLETED)
                    ->orderBy('target_date')
                    ->orderBy('id')
                    ->limit($availableSlots)
                    ->lockForUpdate()
                    ->get();

                if ($tasks->isEmpty()) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.merge_no_completed_tasks'));
                }

                $availableDates = $this->availableDates(
                    $lockedTarget->monthCarbon(),
                    $lockedTarget->tasks()
                        ->pluck('target_date')
                        ->map(static fn (mixed $date): string => Carbon::parse($date)->format('Y-m-d'))
                        ->all(),
                    $tasks->count(),
                );

                foreach ($tasks->values() as $index => $task) {
                    $this->deleteSupersededRetries($lockedSource, $task);

                    $task->update([
                        'project_id' => (int) $lockedTarget->getKey(),
                        'site_id' => $lockedTarget->site_id,
                        'target_date' => $availableDates[$index],
                    ]);
                }

                $sourceTotal = $lockedSource->tasks()->count();
                $targetTotal = $lockedTarget->tasks()->count();

                $lockedSource->update(['total_tasks' => $sourceTotal]);
                $lockedTarget->update(['total_tasks' => $targetTotal]);

                return [
                    'moved' => $tasks->count(),
                    'remaining' => $lockedSource->tasks()
                        ->where('status', SeoProjectTask::STATUS_COMPLETED)
                        ->count(),
                    'target_total' => $targetTotal,
                    'target_capacity' => $lockedTarget->maxTasksAllowed(),
                ];
            },
        );
    }

    /**
     * @param  list<string>  $occupiedDates
     * @return list<string>
     */
    public function availableDates(Carbon|string $month, array $occupiedDates, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $start = Carbon::parse($month)->startOfMonth();
        $occupied = array_fill_keys(
            array_map(
                static fn (string $date): string => Carbon::parse($date)->format('Y-m-d'),
                $occupiedDates,
            ),
            true,
        );
        $dates = [];

        for ($day = 0; $day < $start->daysInMonth && count($dates) < $limit; $day++) {
            $date = $start->copy()->addDays($day)->format('Y-m-d');
            if (! isset($occupied[$date])) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    private function assertProjectsCanMerge(?SeoProject $source, ?SeoProject $target): void
    {
        if (! $source instanceof SeoProject || ! $target instanceof SeoProject) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.merge_invalid_target'));
        }

        if ((int) $source->getKey() === (int) $target->getKey()
            || (int) $source->site_id !== (int) $target->site_id
            || ! $target->monthCarbon()->lt($source->monthCarbon())) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.merge_invalid_target'));
        }
    }

    private function deleteSupersededRetries(SeoProject $source, SeoProjectTask $completedTask): void
    {
        $query = $source->tasks()
            ->whereKeyNot($completedTask->getKey())
            ->where('type', $completedTask->type)
            ->where('source_content', $completedTask->source_content)
            ->whereIn('status', [
                SeoProjectTask::STATUS_PENDING,
                SeoProjectTask::STATUS_FAILED,
            ]);

        if ($completedTask->type === SeoProjectTask::TYPE_NEW_KEYWORD) {
            $query->where('post_type', SeoProjectTask::normalizePostType($completedTask->post_type));
        }

        $query->delete();
    }
}
