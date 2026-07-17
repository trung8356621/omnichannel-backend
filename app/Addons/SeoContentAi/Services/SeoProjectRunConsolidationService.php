<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\SeoPromptResultLink;
use Illuminate\Support\Facades\DB;

final class SeoProjectRunConsolidationService
{
    public function hasRunnablePendingTasks(SeoProject $project): bool
    {
        $this->syncObsoleteTaskStatuses($project);

        $successfulKeys = $this->successfulIdentityKeysFromRuns($project);

        foreach ($project->tasks()->where('status', SeoProjectTask::STATUS_PENDING)->get() as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            if (! isset($successfulKeys[$this->taskIdentityKeyFromTask($task)])) {
                return true;
            }
        }

        return false;
    }

    public function isProjectFullyCompleted(SeoProject $project): bool
    {
        $this->syncObsoleteTaskStatuses($project);

        $identityKeys = $this->uniqueTaskIdentityKeys($project);
        if ($identityKeys === []) {
            return false;
        }

        $successfulKeys = $this->successfulIdentityKeysFromRuns($project);

        foreach ($identityKeys as $key) {
            if (! isset($successfulKeys[$key])) {
                return false;
            }
        }

        return ! $this->hasRunnablePendingTasks($project);
    }

    public function hasSuccessfulRunItem(SeoProject $project, array $item): bool
    {
        $key = $this->itemIdentityKey($item);

        return isset($this->successfulIdentityKeysFromRuns($project)[$key]);
    }

    public function maybeConsolidate(SeoProject $project): ?SeoProjectRun
    {
        $this->syncObsoleteTaskStatuses($project);
        $project->refresh();

        if (! $this->isProjectFullyCompleted($project)) {
            return null;
        }

        $runs = $project->runs()->orderBy('id')->get();
        if ($runs->count() <= 1) {
            $single = $runs->first();
            if ($single instanceof SeoProjectRun) {
                $this->normalizeKeeperRun(
                    $single,
                    $this->collectMergedItems($runs),
                    $single->started_at,
                );

                return $single->fresh();
            }

            return null;
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($runs): SeoProjectRun {
            $mergedItems = $this->collectMergedItems($runs);
            /** @var SeoProjectRun $keeper */
            $keeper = $runs->last();
            $removedIds = $runs
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0 && $id !== (int) $keeper->id)
                ->values()
                ->all();

            $this->normalizeKeeperRun($keeper, $mergedItems, $runs->min('started_at'));

            if ($removedIds !== []) {
                SeoPromptResultLink::query()
                    ->whereIn('project_run_id', $removedIds)
                    ->update(['project_run_id' => (int) $keeper->id]);

                SeoProjectRun::query()->whereIn('id', $removedIds)->delete();
            }

            $this->syncArticleRunMeta($keeper, $mergedItems);

            return $keeper->fresh() ?? $keeper;
        });
    }

    private function syncObsoleteTaskStatuses(SeoProject $project): void
    {
        $successfulKeys = $this->successfulIdentityKeysFromRuns($project);

        if ($successfulKeys === []) {
            return;
        }

        $obsoleteStatuses = [
            SeoProjectTask::STATUS_FAILED,
            SeoProjectTask::STATUS_PENDING,
            SeoProjectTask::STATUS_WRITING,
            SeoProjectTask::STATUS_REVIEWING,
        ];

        foreach ($project->tasks()->whereIn('status', $obsoleteStatuses)->get() as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            if (! isset($successfulKeys[$this->taskIdentityKeyFromTask($task)])) {
                continue;
            }

            $task->update([
                'status' => SeoProjectTask::STATUS_COMPLETED,
                'completed_at' => $task->completed_at ?? now(),
            ]);
        }
    }

    /**
     * @return array<string, true>
     */
    private function successfulIdentityKeysFromRuns(SeoProject $project): array
    {
        $runs = $project->relationLoaded('runs')
            ? $project->runs
            : $project->runs()->orderBy('id')->get();

        $keys = [];

        foreach ($this->collectMergedItems($runs) as $item) {
            if ((string) ($item['status'] ?? '') === 'success') {
                $keys[$this->itemIdentityKey($item)] = true;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function uniqueTaskIdentityKeys(SeoProject $project): array
    {
        $keys = [];

        foreach ($project->tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $keys[$this->taskIdentityKeyFromTask($task)] = true;
        }

        return array_keys($keys);
    }

    private function taskIdentityKeyFromTask(SeoProjectTask $task): string
    {
        return $this->itemIdentityKey([
            'type' => $task->type,
            'post_type' => $task->post_type,
            'source_content' => $task->source_content,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoProjectRun>  $runs
     * @return list<array<string, mixed>>
     */
    private function collectMergedItems($runs): array
    {
        /** @var array<string, array{priority: int, item: array<string, mixed>}> $bucket */
        $bucket = [];

        foreach ($runs as $run) {
            $items = is_array($run->items) ? $run->items : [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $key = $this->itemIdentityKey($item);
                $priority = $this->itemPriority($item, (int) $run->id);

                if (! isset($bucket[$key]) || $priority >= $bucket[$key]['priority']) {
                    $bucket[$key] = [
                        'priority' => $priority,
                        'item' => $item,
                    ];
                }
            }
        }

        return array_values(array_map(
            static fn (array $entry): array => $entry['item'],
            $bucket,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function normalizeKeeperRun(SeoProjectRun $keeper, array $items, mixed $startedAt = null): void
    {
        $succeeded = collect($items)->where('status', 'success')->count();
        $failed = collect($items)->where('status', 'failed')->count();

        $keeper->update([
            'mode' => SeoProjectRun::MODE_FULL,
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'total' => count($items),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'items' => array_values($items),
            'started_at' => $startedAt ?? $keeper->started_at,
            'finished_at' => $keeper->finished_at ?? now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncArticleRunMeta(SeoProjectRun $keeper, array $items): void
    {
        foreach ($items as $item) {
            if ((string) ($item['status'] ?? '') !== 'success') {
                continue;
            }

            $articleId = (int) ($item['article_id'] ?? 0);
            if ($articleId <= 0) {
                continue;
            }

            $article = SeoArticle::query()->find($articleId);
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'content_project_run'],
                [
                    'meta_value' => json_encode([
                        'run_id' => (int) $keeper->id,
                        'project_id' => (int) $keeper->project_id,
                        'task_id' => (int) ($item['task_id'] ?? 0),
                        'ran_at' => ($keeper->finished_at ?? now())->toIso8601String(),
                    ], JSON_UNESCAPED_UNICODE),
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemIdentityKey(array $item): string
    {
        $type = (string) ($item['type'] ?? SeoProjectTask::TYPE_NEW_KEYWORD);
        $source = mb_strtolower(trim((string) ($item['source_content'] ?? '')));
        $postType = SeoProjectTask::isNewArticleType($type)
            ? SeoProjectTask::normalizePostType($item['post_type'] ?? null)
            : '';

        return $type.'|'.$postType.'|'.$source;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemPriority(array $item, int $runId): int
    {
        $statusScore = (string) ($item['status'] ?? '') === 'success' ? 100 : 0;

        return $statusScore + min(99, max(0, $runId));
    }
}
