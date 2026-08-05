<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Enums\SeoProjectRunItemStatus;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectRunItem;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectExecutionStatus;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;

/**
 * Recover orphaned Writing/Generating items into Failed (eligible for Run again / Generate pending).
 * Never force-releases locks owned by a fresh execution.
 */
final class ContentProjectGenerationRecoveryService
{
    public const RECOVERY_MESSAGE = 'Interrupted: stale generation runtime (no heartbeat / no active worker).';

    public function __construct(
        private readonly ContentProjectExecutionStalenessPolicy $staleness,
        private readonly ContentProjectExecutionFinalizer $finalizer,
    ) {}

    /**
     * @return array{recovered_task_ids: list<int>, skipped_task_ids: list<int>, details: list<array<string, mixed>>}
     */
    public function reconcileProject(SeoProject $project): array
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->where('status', SeoProjectTask::STATUS_WRITING)
            ->whereNull('archived_at')
            ->with(['article'])
            ->orderBy('id')
            ->get();

        $recovered = [];
        $skipped = [];
        $details = [];

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $result = $this->recoverTaskIfStale($task);
            if (($result['recovered'] ?? false) === true) {
                $recovered[] = (int) $task->id;
            } else {
                $skipped[] = (int) $task->id;
            }
            $details[] = $result;
        }

        if ($recovered !== []) {
            try {
                $batchId = 'recovery-'.now()->format('YmdHi').'-'.(int) $project->getKey();
                app(\App\Addons\SeoContentAi\Services\Notifications\Publishers\GenerationStuckNotificationPublisher::class)
                    ->notifyRecoveryBatch(
                        $project,
                        $batchId,
                        $recovered,
                        [],
                        exhausted: false,
                    );
            } catch (\Throwable $notificationError) {
                RuntimeLogger::warning('seo.operational_notification.generation_stuck_hook_failed', [
                    'project_id' => (int) $project->getKey(),
                    'error' => $notificationError->getMessage(),
                ]);
            }
        }

        return [
            'recovered_task_ids' => $recovered,
            'skipped_task_ids' => $skipped,
            'details' => $details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recoverTaskIfStale(SeoProjectTask $task): array
    {
        $evaluation = $this->staleness->evaluateTask($task);
        if (! ($evaluation['stale'] ?? false)) {
            return [
                'task_id' => (int) $task->id,
                'recovered' => false,
                'reason' => 'not_stale',
                'evaluation' => $evaluation,
            ];
        }

        return $this->recoverStaleTask($task, $evaluation);
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    public function recoverStaleTask(SeoProjectTask $task, array $evaluation = []): array
    {
        if ($evaluation === []) {
            $evaluation = $this->staleness->evaluateTask($task);
        }
        if (! ($evaluation['stale'] ?? false)) {
            return [
                'task_id' => (int) $task->id,
                'recovered' => false,
                'reason' => 'not_stale',
                'evaluation' => $evaluation,
            ];
        }

        $taskId = (int) $task->getKey();
        $reason = self::RECOVERY_MESSAGE;

        $outcome = DB::connection('omi_seo_ai')->transaction(function () use ($task, $taskId, $evaluation, $reason): array {
            /** @var SeoProjectTask|null $locked */
            $locked = SeoProjectTask::query()->whereKey($taskId)->lockForUpdate()->first();
            if (! $locked instanceof SeoProjectTask) {
                return ['recovered' => false, 'reason' => 'task_missing'];
            }
            if ((string) $locked->status !== SeoProjectTask::STATUS_WRITING) {
                return ['recovered' => false, 'reason' => 'status_changed'];
            }

            // Re-check under lock — do not interrupt a freshly claimed worker.
            $freshEval = $this->staleness->evaluateTask($locked->loadMissing('article'));
            if (! ($freshEval['stale'] ?? false)) {
                return ['recovered' => false, 'reason' => 'became_active', 'evaluation' => $freshEval];
            }

            $finalizedIds = [];
            $staleItemIds = array_map('intval', $freshEval['stale_run_item_ids'] ?? []);
            $activeIds = array_map('intval', $freshEval['active_run_item_ids'] ?? []);
            $targetIds = $staleItemIds !== [] ? $staleItemIds : $activeIds;

            foreach ($targetIds as $itemId) {
                $item = SeoProjectRunItem::query()->find($itemId);
                if (! $item instanceof SeoProjectRunItem) {
                    continue;
                }
                if (! ContentProjectExecutionStatus::isActive((string) $item->status) || $item->finished_at !== null) {
                    continue;
                }
                $this->finalizer->finalize(
                    $item,
                    SeoProjectRunItemStatus::Failed->value,
                    $reason,
                    [
                        'error_code' => ContentProjectExecutionStalenessPolicy::REASON_STALE_RUNTIME,
                    ],
                    syncMirror: true,
                );
                $finalizedIds[] = $itemId;
                $this->releaseStaleDispatchIfOwnedBy($item);
            }

            // Orphan writing with no run items — still mark failed (generation, not publish).
            $payload = [
                'status' => SeoProjectTask::STATUS_FAILED,
            ];
            // Do not write last_publish_error — generation failures belong on run_item.error_message.

            SeoProjectTask::query()->whereKey($taskId)->update($payload);
            $locked->refresh();

            return [
                'recovered' => true,
                'reason' => ContentProjectExecutionStalenessPolicy::REASON_STALE_RUNTIME,
                'finalized_run_item_ids' => $finalizedIds,
                'task_status' => (string) $locked->status,
                'evaluation' => $freshEval,
            ];
        });

        if (($outcome['recovered'] ?? false) === true) {
            RuntimeLogger::info('content_project.generation_stale_recovered', [
                'task_id' => $taskId,
                'project_id' => (int) ($task->project_id ?? 0),
                'finalized_run_item_ids' => $outcome['finalized_run_item_ids'] ?? [],
                'timeout_minutes' => $evaluation['timeout_minutes'] ?? $this->staleness->staleTimeoutMinutes(),
            ]);
        }

        return array_merge(['task_id' => $taskId], $outcome);
    }

    /**
     * Clear active_dispatch only when it points at this finalized stale item (token ownership).
     */
    private function releaseStaleDispatchIfOwnedBy(SeoProjectRunItem $item): void
    {
        $run = SeoProjectRun::query()->find((int) $item->run_id);
        if (! $run instanceof SeoProjectRun) {
            return;
        }

        $settings = is_array($run->settings ?? null) ? $run->settings : [];
        $engine = is_array($settings['engine'] ?? null) ? $settings['engine'] : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        if ($active === null) {
            return;
        }

        $ownedItemId = (int) ($active['run_item_id'] ?? 0);
        if ($ownedItemId !== (int) $item->id) {
            // Lock belongs to another execution — do not force-release.
            return;
        }

        $token = trim((string) ($active['token'] ?? ''));
        unset($engine['active_dispatch']);
        $settings['engine'] = $engine;

        SeoProjectRun::query()->whereKey((int) $run->id)->update([
            'settings' => $settings,
        ]);

        RuntimeLogger::info('content_project.stale_dispatch_released', [
            'run_id' => (int) $run->id,
            'run_item_id' => (int) $item->id,
            'token_present' => $token !== '',
        ]);
    }
}
