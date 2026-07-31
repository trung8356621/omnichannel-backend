<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Enums\ContentProjectLifecyclePhase;
use App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectLifecycle;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPublishTransitionGuard;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Publishing Queue operations — batch-first, không đụng AI workflow.
 */
final class ContentProjectPublishingQueueService
{
    public function __construct(
        private readonly ContentProjectLifecycle $lifecycle,
        private readonly ContentProjectPublishTransitionGuard $transitionGuard,
    ) {}

    /**
     * @param  list<int>  $taskIds
     * @return int affected
     */
    public function schedule(SeoProject $project, array $taskIds, Carbon $at): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        $this->assertTasksEligibleForSchedule($project, $ids);

        return $this->batchUpdate($project, $ids, [
            'scheduled_publish_at' => $at,
            'publish_queue_status' => ContentProjectPublishQueueStatus::Waiting->value,
            'last_publish_error' => null,
        ]);
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function unschedule(SeoProject $project, array $taskIds): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        return $this->batchUpdate($project, $ids, [
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
            'last_publish_error' => null,
        ], onlyStatuses: [
            ContentProjectPublishQueueStatus::Waiting->value,
            ContentProjectPublishQueueStatus::Retrying->value,
            ContentProjectPublishQueueStatus::None->value,
            ContentProjectPublishQueueStatus::Failed->value,
        ]);
    }

    /**
     * Explicit Publish Now — normalize due time + Waiting, then runner publishes via WP.
     * Past/null scheduled_publish_at must not block this path.
     *
     * @param  list<int>  $taskIds
     */
    public function publishNow(SeoProject $project, array $taskIds): int
    {
        return $this->enqueueExplicitPublish($project, $taskIds, asRetry: false);
    }

    /**
     * Explicit Retry Publish — Failed/Cancelled (and stale due Waiting) become queue-eligible.
     * Past/null scheduled_publish_at must not block; clears stale failure fields.
     *
     * @param  list<int>  $taskIds
     */
    public function retry(SeoProject $project, array $taskIds): int
    {
        return $this->enqueueExplicitPublish($project, $taskIds, asRetry: true);
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function enqueueExplicitPublish(SeoProject $project, array $taskIds, bool $asRetry): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        $this->assertTasksEligibleForSchedule($project, $ids);

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            return $this->batchUpdate($project, $ids, [
                'scheduled_publish_at' => now(),
            ]);
        }

        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->whereNull('archived_at')
            ->get();

        $affected = 0;
        $now = now();

        foreach ($tasks as $task) {
            $from = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
                ?? ContentProjectPublishQueueStatus::None;

            if ($from === ContentProjectPublishQueueStatus::Processing) {
                continue;
            }

            if ($asRetry) {
                $retryable = in_array($from, [
                    ContentProjectPublishQueueStatus::Failed,
                    ContentProjectPublishQueueStatus::Cancelled,
                    ContentProjectPublishQueueStatus::Waiting,
                    ContentProjectPublishQueueStatus::Retrying,
                    ContentProjectPublishQueueStatus::None,
                    ContentProjectPublishQueueStatus::Published,
                ], true);
                if (! $retryable) {
                    continue;
                }

                $to = $from === ContentProjectPublishQueueStatus::Failed
                    ? ContentProjectPublishQueueStatus::Retrying
                    : ContentProjectPublishQueueStatus::Waiting;
            } else {
                // Published → Waiting = update existing WP post with latest local content.
                $to = ContentProjectPublishQueueStatus::Waiting;
            }

            $this->transitionGuard->assertCanTransition($from, $to);

            $payload = [
                'scheduled_publish_at' => $now,
                'publish_queue_status' => $to->value,
                'last_publish_error' => null,
            ];

            if ($asRetry && $from === ContentProjectPublishQueueStatus::Failed) {
                $payload['publish_retry_count'] = DB::raw('publish_retry_count + 1');
            }

            $updated = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->whereKey((int) $task->getKey())
                ->whereNull('archived_at')
                ->update($payload);

            $affected += (int) $updated;
        }

        RuntimeLogger::info($asRetry ? 'content_project_publish_retry' : 'content_project_publish_now', [
            'project_id' => (int) $project->getKey(),
            'affected' => $affected,
            'as_retry' => $asRetry,
        ]);

        return $affected;
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function skip(SeoProject $project, array $taskIds): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        $this->assertTransitionForTasks($project, $ids, ContentProjectPublishQueueStatus::Skipped);

        return $this->batchUpdate($project, $ids, [
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::Skipped->value,
            'last_publish_error' => null,
        ], onlyStatuses: array_merge(
            ContentProjectPublishQueueStatus::activeValues(),
            [ContentProjectPublishQueueStatus::Failed->value, ContentProjectPublishQueueStatus::Waiting->value],
        ));
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function cancelPublish(SeoProject $project, array $taskIds): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        $this->assertTransitionForTasks($project, $ids, ContentProjectPublishQueueStatus::Cancelled);

        return $this->batchUpdate($project, $ids, [
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::Cancelled->value,
            'last_publish_error' => null,
        ], onlyStatuses: array_merge(
            ContentProjectPublishQueueStatus::activeValues(),
            [ContentProjectPublishQueueStatus::Failed->value],
        ));
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function moveTime(SeoProject $project, array $taskIds, Carbon $at): int
    {
        return $this->schedule($project, $taskIds, $at);
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function clearSchedule(SeoProject $project, array $taskIds): int
    {
        return $this->unschedule($project, $taskIds);
    }

    public function markProcessing(SeoProjectTask $task): void
    {
        $task->forceFill([
            'publish_queue_status' => ContentProjectPublishQueueStatus::Processing->value,
            'last_publish_attempt_at' => now(),
        ])->saveQuietly();
    }

    public function markPublished(SeoProjectTask $task): void
    {
        $task->forceFill([
            'publish_queue_status' => ContentProjectPublishQueueStatus::Published->value,
            'publish_published_at' => now(),
            'scheduled_publish_at' => null,
            'last_publish_error' => null,
        ])->saveQuietly();
    }

    public function markFailed(SeoProjectTask $task, string $error): void
    {
        $task->forceFill([
            'publish_queue_status' => ContentProjectPublishQueueStatus::Failed->value,
            'last_publish_attempt_at' => now(),
            'last_publish_error' => mb_substr(trim($error), 0, 2000),
        ])->saveQuietly();
    }

    private function assertProjectActive(SeoProject $project): void
    {
        if ($project->archived_at !== null || $project->isArchive()) {
            throw new RuntimeException('Project đã Archived — không thao tác Publishing Queue.');
        }
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function assertTasksEligibleForSchedule(SeoProject $project, array $taskIds): void
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $taskIds)
            ->with(['article'])
            ->get();

        foreach ($tasks as $task) {
            $phase = $this->lifecycle->resolvePhase($task);
            if ($phase === ContentProjectLifecyclePhase::Archived) {
                throw new RuntimeException('Không schedule item Archived.');
            }
            if ($phase === ContentProjectLifecyclePhase::Generating) {
                throw new RuntimeException('Không schedule khi AI đang chạy trên item.');
            }
            if ($phase === ContentProjectLifecyclePhase::Draft) {
                throw new RuntimeException('Item Draft chưa đủ điều kiện Publish.');
            }
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $attributes
     * @param  list<string>|null  $onlyStatuses
     */
    private function batchUpdate(
        SeoProject $project,
        array $ids,
        array $attributes,
        ?array $onlyStatuses = null,
    ): int {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            // Fallback: chỉ stamp schedule.
            $query = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->whereIn('id', $ids);

            return (int) $query->update([
                'scheduled_publish_at' => $attributes['scheduled_publish_at'] ?? null,
            ]);
        }

        $query = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->whereNull('archived_at');

        if ($onlyStatuses !== null) {
            $query->where(function ($q) use ($onlyStatuses): void {
                $q->whereIn('publish_queue_status', $onlyStatuses)
                    ->orWhereNull('publish_queue_status');
            });
        }

        return (int) $query->update($attributes);
    }

    /**
     * @param  list<int>  $ids
     */
    private function assertTransitionForTasks(
        SeoProject $project,
        array $ids,
        ContentProjectPublishQueueStatus $to,
    ): void {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->get();

        foreach ($tasks as $task) {
            $from = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
                ?? ContentProjectPublishQueueStatus::None;
            $this->transitionGuard->assertCanTransition($from, $to);
        }
    }

    /**
     * @param  list<int|string>  $taskIds
     * @return list<int>
     */
    private function normalizeIds(array $taskIds): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
