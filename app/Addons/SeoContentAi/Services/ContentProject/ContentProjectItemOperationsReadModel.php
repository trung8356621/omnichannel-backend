<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Enums\ContentProjectItemAction;
use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectRunItem;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectLifecycle;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStatusBadgePresenter;
use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical Project Items operations read-model (generation + review + publishing).
 */
final class ContentProjectItemOperationsReadModel
{
    public function __construct(
        private readonly ContentProjectLifecycle $lifecycle,
        private readonly ContentProjectDashboardStatsService $stats,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly ContentProjectExecutionStalenessPolicy $staleness,
        private readonly ContentProjectGenerationRecoveryService $generationRecovery,
    ) {}

    /**
     * @param  array{
     *     search?: string,
     *     type?: string,
     *     generation?: string,
     *     lifecycle?: string,
     *     queue?: string,
     *     scheduled?: string,
     *     failed_only?: bool,
     *     page?: int,
     *     per_page?: int,
     *     reconcile_stale?: bool,
     * }  $filters
     * @return array{
     *     project_id: int,
     *     stats: array<string, int>,
     *     last_execution_at: string|null,
     *     last_execution_status: string|null,
     *     rows: list<array<string, mixed>>,
     *     paginator: LengthAwarePaginator,
     * }
     */
    public function forProject(SeoProject $project, array $filters = []): array
    {
        $projectId = (int) $project->getKey();
        if (($filters['reconcile_stale'] ?? true) === true) {
            // Once per HTTP request — page visit reconciles without auto-starting generation.
            $guardKey = 'seo.cp.stale_gen_reconciled.'.$projectId;
            if (! app()->bound($guardKey)) {
                $this->generationRecovery->reconcileProject($project);
                app()->instance($guardKey, true);
            }
        }

        $baseStats = $this->stats->forProject($project);

        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->planned()
            ->with(['article'])
            ->orderBy('id')
            ->get();

        $latestByTask = $this->latestRunItemsByTaskIds(
            $tasks->map(static fn (SeoProjectTask $t): int => (int) $t->id)->all(),
        );

        $latestRun = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->first();

        $rows = [];
        $index = 0;
        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $index++;
            $rows[] = $this->mapRow($task, $index, $latestByTask[(int) $task->id] ?? null);
        }

        $generated = 0;
        $pending = 0;
        $running = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $gs = (string) $row['generation_status'];
            if (! empty($row['is_genuinely_running'])) {
                $running++;
            } elseif ($gs === SeoProjectTask::STATUS_FAILED || ! empty($row['is_generation_stale'])) {
                $failed++;
            } elseif ($row['can_generate'] === true) {
                $pending++;
            } else {
                $generated++;
            }
        }

        $filtered = $this->applyFilters(collect($rows), $filters);
        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 30)));
        $page = max(1, (int) ($filters['page'] ?? LengthAwarePaginator::resolveCurrentPage()));
        $total = $filtered->count();
        $slice = $filtered->forPage($page, $perPage)->values()->all();

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );

        return [
            'project_id' => $projectId,
            'stats' => [
                'total_items' => count($rows),
                'generated' => $generated,
                'pending' => $pending,
                'running' => $running,
                'failed' => $failed,
                'waiting_review' => (int) ($baseStats['waiting_review'] ?? 0),
                'approved' => (int) ($baseStats['approved'] ?? 0),
                'waiting_publish' => (int) ($baseStats['waiting_publish'] ?? 0),
                'published' => (int) ($baseStats['published'] ?? 0),
            ],
            'last_execution_at' => $latestRun?->finished_at?->format('d/m/Y H:i')
                ?? $latestRun?->started_at?->format('d/m/Y H:i'),
            'last_execution_status' => $latestRun !== null ? (string) $latestRun->status : null,
            'rows' => $slice,
            'paginator' => $paginator,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $exec
     * @return array<string, mixed>
     */
    private function mapRow(SeoProjectTask $task, int $index, ?array $exec): array
    {
        $tid = (int) $task->id;
        $article = $task->article;
        $staleEval = $this->staleness->evaluateTask($task);
        $isStaleGeneration = (bool) ($staleEval['stale'] ?? false);
        $runError = $exec !== null
            ? trim((string) ($exec['error_message'] ?? $exec['message'] ?? ''))
            : '';
        $state = $this->lifecycle->resolveState(
            $task,
            $article instanceof SeoArticle ? $article : null,
            [
                'run_item_status' => $exec['status'] ?? null,
                'run_item_error' => $runError !== '' ? $runError : null,
                'stale_generation' => $isStaleGeneration,
                'execution_running' => (bool) ($staleEval['has_fresh_active_execution'] ?? false),
            ],
        );
        $phase = $state->lifecycleState;
        $type = SeoProjectTask::normalizeType($task->type);

        $articleId = (int) ($task->article_id ?? 0);
        $keyword = trim((string) ($task->keyword ?? ''));
        $title = trim((string) ($task->title ?? ''));
        if ($title === '' && $article instanceof SeoArticle) {
            $title = trim((string) ($article->title ?? ''));
        }
        $source = trim((string) ($task->source_content ?? ''));
        if ($keyword === '' && $source !== '' && $type !== SeoProjectTask::TYPE_IMPROVE) {
            $keyword = $source;
        }

        $primary = $title !== '' ? $title : ($keyword !== '' ? $keyword : '#'.$tid);

        $execStatusEarly = strtolower((string) ($exec['status'] ?? ''));
        $latestAttemptQueued = in_array($execStatusEarly, ['pending', 'processing'], true);

        $message = $state->currentError ?? '';
        if ($latestAttemptQueued) {
            // New attempt accepted — hide stale failed message on the row.
            $message = '';
        }
        if ($message === '' && $state->currentErrorSource->value === 'publish' && $task->last_publish_error !== null) {
            $message = (string) $task->last_publish_error;
        }

        $genStatus = (string) ($task->status ?? 'pending');
        if ($latestAttemptQueued && $genStatus === SeoProjectTask::STATUS_FAILED) {
            // Prefer latest run-item attempt over sticky task.failed until worker claims.
            $genStatus = SeoProjectTask::STATUS_PENDING;
        }
        $queueStatus = (string) ($task->publish_queue_status ?? 'none');
        if ($queueStatus === '') {
            $queueStatus = 'none';
        }

        $lastActivityCarbon = $this->resolveLastActivity($task, $article, $exec);
        $isGenuineRunning = (string) ($task->status ?? '') === SeoProjectTask::STATUS_WRITING
            && ! $isStaleGeneration
            && (bool) ($staleEval['has_fresh_active_execution'] ?? false);
        if ($execStatusEarly === 'processing' && ! $isStaleGeneration) {
            $isGenuineRunning = true;
            $genStatus = SeoProjectTask::STATUS_WRITING;
        }
        $hasResumableCheckpoint = ! $isGenuineRunning
            && ! $isStaleGeneration
            && $exec !== null
            && in_array(strtolower((string) ($exec['status'] ?? '')), ['failed', 'cancelled', 'stopped', 'timeout'], true)
            && trim((string) ($exec['action'] ?? '')) !== '';

        $displayGenStatus = $isStaleGeneration ? SeoProjectTask::STATUS_FAILED : $genStatus;
        $displayPhase = $phase;

        $genBadge = ContentProjectStatusBadgePresenter::generation($displayGenStatus, $exec['status'] ?? null);
        $lifeBadge = ContentProjectStatusBadgePresenter::lifecycle($displayPhase->value);
        $queueBadge = ContentProjectStatusBadgePresenter::queue($queueStatus);

        if ($isStaleGeneration && ($message === '' || $message === null)) {
            $message = ContentProjectGenerationRecoveryService::RECOVERY_MESSAGE;
        }

        return [
            'index' => $index,
            'task_id' => $tid,
            'type' => $type,
            'type_label' => match ($type) {
                SeoProjectTask::TYPE_REWRITE => 'rewrite',
                SeoProjectTask::TYPE_IMPROVE => 'improve',
                default => 'new',
            },
            'primary_label' => $primary,
            'keyword' => $keyword !== '' ? $keyword : '—',
            'title' => $title !== '' ? $title : '—',
            'article_id' => $articleId > 0 ? $articleId : null,
            'article_edit_url' => $articleId > 0
                ? ArticleResource::getUrl('edit', ['record' => $articleId])
                : null,
            'article_slug' => $article instanceof SeoArticle ? (string) ($article->slug ?? '') : '',
            'generation_status' => $displayGenStatus,
            'execution_status' => $exec['status'] ?? null,
            'current_step' => $exec['action'] ?? null,
            'lifecycle' => $displayPhase->value,
            'queue_status' => $queueStatus,
            'item_state' => $state->toArray(),
            'current_error_source' => $state->currentErrorSource->value,
            'available_actions' => array_map(
                static fn ($a): string => $a->value,
                $state->availableActions,
            ),
            'scheduled_at' => $task->scheduled_publish_at?->format('d/m/Y H:i'),
            'scheduled_raw' => $task->scheduled_publish_at?->toIso8601String(),
            'is_scheduled' => $task->scheduled_publish_at !== null,
            'message' => $message !== '' ? $message : null,
            'last_activity' => $lastActivityCarbon?->diffForHumans() ?? '—',
            'last_activity_full' => $lastActivityCarbon?->format('d/m/Y H:i:s'),
            'last_run_at' => $exec['finished_at'] ?? $exec['started_at'] ?? null,
            // Batch E: derive solely from ActionGuard availableActions — single source of truth.
            'can_generate' => in_array(ContentProjectItemAction::Generate, $state->availableActions, true),
            'can_regen' => $articleId > 0
                && $type !== SeoProjectTask::TYPE_IMPROVE
                && in_array(ContentProjectItemAction::Rerun, $state->availableActions, true),
            'can_run_again' => $isStaleGeneration
                || (string) ($task->status ?? '') === SeoProjectTask::STATUS_FAILED,
            'is_generation_stale' => $isStaleGeneration,
            'is_genuinely_running' => $isGenuineRunning,
            'has_resumable_checkpoint' => $hasResumableCheckpoint,
            'is_improve' => $type === SeoProjectTask::TYPE_IMPROVE,
            'generation_badge' => $genBadge,
            'lifecycle_badge' => $lifeBadge,
            'queue_badge' => $queueBadge,
            'has_unpublished_changes' => $article instanceof SeoArticle
                && $this->syncFlags->hasUnpublishedChanges($article),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $rows, array $filters): Collection
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $type = trim((string) ($filters['type'] ?? ''));
        $generation = trim((string) ($filters['generation'] ?? ''));
        $lifecycle = trim((string) ($filters['lifecycle'] ?? ''));
        $queue = trim((string) ($filters['queue'] ?? ''));
        $scheduled = trim((string) ($filters['scheduled'] ?? ''));
        $failedOnly = (bool) ($filters['failed_only'] ?? false);

        return $rows->filter(static function (array $row) use (
            $search,
            $type,
            $generation,
            $lifecycle,
            $queue,
            $scheduled,
            $failedOnly
        ): bool {
            if ($search !== '') {
                $hay = strtolower(implode(' ', [
                    (string) $row['primary_label'],
                    (string) $row['keyword'],
                    (string) $row['title'],
                    (string) ($row['article_slug'] ?? ''),
                    (string) $row['task_id'],
                    (string) ($row['article_id'] ?? ''),
                ]));
                if (! str_contains($hay, $search)) {
                    return false;
                }
            }

            if ($type !== '' && (string) $row['type'] !== $type) {
                return false;
            }

            if ($generation !== '') {
                $gs = (string) $row['generation_status'];
                $ok = match ($generation) {
                    'pending' => $gs === SeoProjectTask::STATUS_PENDING || ($row['can_generate'] ?? false) === true,
                    'running' => $gs === SeoProjectTask::STATUS_WRITING,
                    'success' => in_array($gs, [SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::STATUS_REVIEWING], true),
                    'failed' => $gs === SeoProjectTask::STATUS_FAILED,
                    default => $gs === $generation,
                };
                if (! $ok) {
                    return false;
                }
            }

            if ($lifecycle !== '') {
                $wanted = array_filter(array_map('trim', explode(',', $lifecycle)));
                if ($wanted !== [] && ! in_array((string) $row['lifecycle'], $wanted, true)) {
                    return false;
                }
            }

            if ($queue !== '' && (string) $row['queue_status'] !== $queue) {
                return false;
            }

            if ($scheduled === 'yes' && ! ($row['is_scheduled'] ?? false)) {
                return false;
            }
            if ($scheduled === 'no' && ($row['is_scheduled'] ?? false)) {
                return false;
            }

            if ($failedOnly) {
                // Latest attempt only — queued/running after rerun must leave Failed-only.
                if (! empty($row['is_genuinely_running'])) {
                    return false;
                }
                $execStatus = strtolower((string) ($row['execution_status'] ?? ''));
                if (in_array($execStatus, ['pending', 'processing'], true)) {
                    return false;
                }
                $genFail = (string) $row['generation_status'] === SeoProjectTask::STATUS_FAILED
                    || ! empty($row['is_generation_stale']);
                if (! $genFail) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @param  array<string, mixed>|null  $exec
     */
    private function resolveLastActivity(SeoProjectTask $task, mixed $article, ?array $exec): ?Carbon
    {
        $candidates = [];
        if ($article instanceof SeoArticle) {
            foreach ([$article->last_manual_saved_at, $article->last_synced_at, $article->updated_at] as $dt) {
                if ($dt !== null) {
                    $candidates[] = Carbon::parse($dt);
                }
            }
        }
        if ($task->updated_at !== null) {
            $candidates[] = Carbon::parse($task->updated_at);
        }
        foreach (['finished_at', 'started_at'] as $key) {
            if (! empty($exec[$key])) {
                try {
                    $candidates[] = Carbon::createFromFormat('d/m/Y H:i', (string) $exec[$key]);
                } catch (\Throwable) {
                    try {
                        $candidates[] = Carbon::parse((string) $exec[$key]);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (Carbon $a, Carbon $b): int => $b <=> $a);

        return $candidates[0];
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array<string, mixed>>
     */
    private function latestRunItemsByTaskIds(array $taskIds): array
    {
        if ($taskIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
            return [];
        }

        $items = SeoProjectRunItem::query()
            ->whereIn('task_id', $taskIds)
            ->orderByDesc('id')
            ->get(['id', 'task_id', 'run_id', 'status', 'action', 'error_message', 'started_at', 'finished_at']);

        $map = [];
        foreach ($items as $item) {
            $tid = (int) $item->task_id;
            if ($tid <= 0 || isset($map[$tid])) {
                continue;
            }
            $map[$tid] = [
                'id' => (int) $item->id,
                'run_id' => (int) $item->run_id,
                'status' => (string) ($item->status ?? ''),
                'action' => $item->action !== null ? (string) $item->action : null,
                'error_message' => $item->error_message !== null ? (string) $item->error_message : null,
                'message' => null,
                'started_at' => $item->started_at?->format('d/m/Y H:i'),
                'finished_at' => $item->finished_at?->format('d/m/Y H:i'),
            ];
        }

        return $map;
    }
}
