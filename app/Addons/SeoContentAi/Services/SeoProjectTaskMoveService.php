<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SeoProjectTaskMoveService
{
    /**
     * Xóa project: chuyển toàn bộ task (đã chạy / chưa chạy) về tháng trước cùng domain.
     * Tháng trước chưa có thì tạo; đã đầy thì chặn xóa.
     *
     * @return array{moved: int, target_project_id: int|null, target_month: string|null}
     */
    public function deleteProjectRollingBackToPreviousMonth(SeoProject $project): array
    {
        return DB::connection($project->getConnectionName())->transaction(function () use ($project): array {
            /** @var SeoProject|null $locked */
            $locked = SeoProject::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProject) {
                throw new RuntimeException(__('seo-content-ai::filament.projects.delete_failed'));
            }

            if ($locked->isArchive()) {
                throw ValidationException::withMessages([
                    'project' => __('seo-content-ai::filament.projects.delete_archive_forbidden'),
                ]);
            }

            $tasks = $locked->tasks()
                ->orderBy('target_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($tasks->isEmpty()) {
                $locked->delete();

                return [
                    'moved' => 0,
                    'target_project_id' => null,
                    'target_month' => null,
                ];
            }

            $target = $this->findOrCreatePreviousMonthProject($locked);
            $target->setRelation(
                'tasks',
                $target->tasks()->orderBy('id')->lockForUpdate()->get(),
            );
            $this->assertTargetHasCapacity($target, $tasks->count());

            $this->appendTasksToProject($target, $tasks);
            $locked->syncTotalTasksCounter();
            $locked->delete();

            app(SeoProjectArticleOwnerSyncService::class)->syncProjectArticles($target->fresh() ?? $target);

            return [
                'moved' => $tasks->count(),
                'target_project_id' => (int) $target->getKey(),
                'target_month' => $target->monthCarbon()->format('m/Y'),
            ];
        });
    }

    /**
     * Chuyển một hoặc nhiều task sang project tháng khác (cùng domain).
     *
     * @param  list<int>  $taskIds
     * @return array{moved: int, target_project_id: int, target_month: string}
     */
    public function moveTasksToProject(SeoProject $source, SeoProject $target, array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($taskIds === []) {
            throw ValidationException::withMessages([
                'task_id' => __('seo-content-ai::filament.projects.move_task_missing'),
            ]);
        }

        if ((int) $source->getKey() === (int) $target->getKey()) {
            throw ValidationException::withMessages([
                'target_project_id' => __('seo-content-ai::filament.projects.move_same_project'),
            ]);
        }

        if ((int) ($source->site_id ?? 0) <= 0
            || (int) ($target->site_id ?? 0) !== (int) $source->site_id
        ) {
            throw ValidationException::withMessages([
                'target_project_id' => __('seo-content-ai::filament.projects.move_domain_mismatch'),
            ]);
        }

        return DB::connection($source->getConnectionName())->transaction(function () use ($source, $target, $taskIds): array {
            /** @var SeoProject|null $lockedSource */
            $lockedSource = SeoProject::query()
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->first();

            /** @var SeoProject|null $lockedTarget */
            $lockedTarget = SeoProject::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedSource instanceof SeoProject || ! $lockedTarget instanceof SeoProject) {
                throw new RuntimeException(__('seo-content-ai::filament.projects.move_failed'));
            }

            $tasks = $lockedSource->tasks()
                ->whereIn('id', $taskIds)
                ->orderBy('target_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($tasks->count() !== count($taskIds)) {
                throw ValidationException::withMessages([
                    'task_id' => __('seo-content-ai::filament.projects.move_task_missing'),
                ]);
            }

            $lockedTarget->setRelation(
                'tasks',
                $lockedTarget->tasks()->orderBy('id')->lockForUpdate()->get(),
            );

            $this->assertTargetHasCapacity($lockedTarget, $tasks->count());
            $this->appendTasksToProject($lockedTarget, $tasks);
            $lockedSource->syncTotalTasksCounter();

            app(SeoProjectArticleOwnerSyncService::class)->syncProjectArticles($lockedTarget->fresh() ?? $lockedTarget);

            return [
                'moved' => $tasks->count(),
                'target_project_id' => (int) $lockedTarget->getKey(),
                'target_month' => $lockedTarget->monthCarbon()->format('m/Y'),
            ];
        });
    }

    /**
     * @return array<int, string>
     */
    public function moveTargetOptions(SeoProject $source): array
    {
        $siteId = (int) ($source->site_id ?? 0);
        if ($siteId <= 0) {
            return [];
        }

        return SeoProject::query()
            ->where('site_id', $siteId)
            ->whereKeyNot($source->getKey())
            ->where(function ($query): void {
                $query
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            })
            ->orderByDesc('month')
            ->orderBy('id')
            ->get()
            ->filter(static fn (SeoProject $project): bool => $project->remainingTaskCapacity() > 0)
            ->mapWithKeys(static function (SeoProject $project): array {
                $remaining = $project->remainingTaskCapacity();
                $max = $project->maxTasksAllowed();
                $count = $project->registeredTaskCount();

                return [
                    (int) $project->getKey() => __('seo-content-ai::filament.projects.move_target_option', [
                        'name' => (string) $project->name,
                        'month' => $project->monthCarbon()->format('m/Y'),
                        'count' => $count,
                        'max' => $max,
                        'remaining' => $remaining,
                    ]),
                ];
            })
            ->all();
    }

    public function findOrCreatePreviousMonthProject(SeoProject $source): SeoProject
    {
        $siteId = (int) ($source->site_id ?? 0);
        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => __('seo-content-ai::filament.projects.domain_required'),
            ]);
        }

        $previousMonth = $source->monthCarbon()->copy()->subMonthNoOverflow()->startOfMonth();

        return $this->findOrCreateProjectForMonth($source, $previousMonth);
    }

    public function findOrCreateProjectForMonth(SeoProject $source, Carbon|string $month): SeoProject
    {
        $siteId = (int) ($source->site_id ?? 0);
        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => __('seo-content-ai::filament.projects.domain_required'),
            ]);
        }

        $carbonMonth = Carbon::parse($month)->startOfMonth();
        $monthDate = $carbonMonth->format('Y-m-d');

        $sameOwner = SeoProject::query()
            ->where('site_id', $siteId)
            ->whereDate('month', $monthDate)
            ->where('user_id', (int) $source->user_id)
            ->where(function ($query): void {
                $query->where('kind', SeoProject::KIND_MONTHLY)->orWhereNull('kind');
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($sameOwner instanceof SeoProject) {
            return $sameOwner;
        }

        $any = SeoProject::query()
            ->where('site_id', $siteId)
            ->whereDate('month', $monthDate)
            ->where(function ($query): void {
                $query->where('kind', SeoProject::KIND_MONTHLY)->orWhereNull('kind');
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($any instanceof SeoProject) {
            return $any;
        }

        return SeoProject::query()->create([
            'name' => SeoProject::defaultNameFromMonth($carbonMonth),
            'user_id' => (int) $source->user_id,
            'site_id' => $siteId,
            'month' => $monthDate,
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
            'description' => null,
        ]);
    }

    public function assertTargetHasCapacity(SeoProject $target, int $incomingCount): void
    {
        if ($incomingCount <= 0) {
            return;
        }

        $remaining = $target->remainingTaskCapacity();
        if ($incomingCount <= $remaining) {
            return;
        }

        throw ValidationException::withMessages([
            'target_project_id' => __('seo-content-ai::filament.projects.move_target_full', [
                'month' => $target->monthCarbon()->format('m/Y'),
                'remaining' => $remaining,
                'needed' => $incomingCount,
                'max' => $target->maxTasksAllowed(),
            ]),
        ]);
    }

    /**
     * @param  Collection<int, SeoProjectTask>  $tasks
     */
    private function appendTasksToProject(SeoProject $target, Collection $tasks): void
    {
        $startIndex = $target->registeredTaskCount();
        $monthStart = $target->monthCarbon();

        foreach ($tasks->values() as $index => $task) {
            $task->update([
                'project_id' => (int) $target->getKey(),
                'target_date' => $monthStart->copy()->addDays($startIndex + $index)->format('Y-m-d'),
            ]);
        }

        $target->syncTotalTasksCounter();
    }
}
