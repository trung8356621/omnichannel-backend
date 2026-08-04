<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing Queue Health — scoped per SEO database connection when possible.
 *
 * Global keys remain as legacy fallback; project UI MUST pass connection_id from
 * SeoConnectionContext so stale connection A cannot paint connection B unhealthy.
 */
final class ContentProjectQueueHealthService
{
    public const CACHE_LAST_WORKER_RUN = 'seo.content_project.publish_queue.last_worker_run';

    public const CACHE_LAST_SUCCESS = 'seo.content_project.publish_queue.last_success';

    public const CACHE_LAST_FAILURE = 'seo.content_project.publish_queue.last_failure';

    public const CACHE_LAST_BOOTSTRAP_FAILURE = 'seo.content_project.publish_queue.last_bootstrap_failure';

    public const RUNNER_STALE_MINUTES = 5;

    /**
     * @param  list<int>|null  $siteIds
     * @return array{
     *     waiting: int,
     *     processing: int,
     *     failed: int,
     *     retrying: int,
     *     stuck_publishing: int,
     *     runner_healthy: bool,
     *     connection_bootstrap_ok: bool,
     *     runner_status: string,
     *     runner_status_label: string,
     *     runner_last_ran_minutes_ago: int|null,
     *     last_worker_run: string|null,
     *     last_success: string|null,
     *     last_failure: string|null,
     *     last_bootstrap_failure: string|null,
     *     health_connection_id: int|null,
     *     health_hash_id: string|null,
     * }
     */
    public function snapshot(?array $siteIds = null, ?int $connectionId = null): array
    {
        $waiting = 0;
        $processing = 0;
        $failed = 0;
        $retrying = 0;
        $stuck = 0;

        try {
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
                $base = \App\Addons\SeoContentAi\Models\SeoProjectTask::query()
                    ->active()
                    ->whereHas('project', static function ($q) use ($siteIds): void {
                        $q->whereNull('archived_at');
                        if (is_array($siteIds) && $siteIds !== []) {
                            $q->whereIn('site_id', $siteIds);
                        }
                    });

                $waiting = (int) (clone $base)->where('publish_queue_status', \App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus::Waiting->value)->count();
                $processing = (int) (clone $base)->where('publish_queue_status', \App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus::Processing->value)->count();
                $failed = (int) (clone $base)->where('publish_queue_status', \App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus::Failed->value)->count();
                $retrying = (int) (clone $base)->where('publish_queue_status', \App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus::Retrying->value)->count();

                $ttl = \App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueueStuckPublishingDefinition::TTL_MINUTES;
                $pastDueGrace = \App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueueStuckPublishingDefinition::PAST_DUE_GRACE_MINUTES;
                $stuck = (int) (clone $base)
                    ->where('publish_queue_status', \App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus::Processing->value)
                    ->where(static function ($q) use ($ttl, $pastDueGrace): void {
                        $q->whereNull('last_publish_attempt_at')
                            ->orWhere('last_publish_attempt_at', '<=', now()->subMinutes($ttl))
                            ->orWhere('scheduled_publish_at', '<=', now()->subMinutes($pastDueGrace));
                    })
                    ->count();
            }
        } catch (\Throwable) {
            // Health UI must not crash when SEO DB is unavailable.
        }

        $resolvedConnectionId = $connectionId;
        $resolvedHash = null;
        if ($resolvedConnectionId === null || $resolvedConnectionId <= 0) {
            $current = SeoConnectionContext::current();
            if ($current instanceof SeoDatabaseConnection) {
                $resolvedConnectionId = (int) $current->getKey();
                $resolvedHash = (string) $current->hash_id;
            }
        } else {
            $resolvedHash = SeoDatabaseConnection::query()
                ->whereKey($resolvedConnectionId)
                ->value('hash_id');
            $resolvedHash = is_string($resolvedHash) ? $resolvedHash : null;
        }

        $scopeId = ($resolvedConnectionId !== null && $resolvedConnectionId > 0)
            ? $resolvedConnectionId
            : null;

        $lastRun = $this->cacheString($this->scopedKey(self::CACHE_LAST_WORKER_RUN, $scopeId));
        $lastSuccess = $this->cacheString($this->scopedKey(self::CACHE_LAST_SUCCESS, $scopeId));
        $lastBootstrapFailure = $this->cacheString($this->scopedKey(self::CACHE_LAST_BOOTSTRAP_FAILURE, $scopeId));
        $lastFailure = $this->cacheString($this->scopedKey(self::CACHE_LAST_FAILURE, $scopeId));

        // Legacy unscoped keys only when no connection context (should be rare in panel).
        if ($scopeId === null) {
            $lastRun ??= $this->cacheString(self::CACHE_LAST_WORKER_RUN);
            $lastSuccess ??= $this->cacheString(self::CACHE_LAST_SUCCESS);
            $lastBootstrapFailure ??= $this->cacheString(self::CACHE_LAST_BOOTSTRAP_FAILURE);
            $lastFailure ??= $this->cacheString(self::CACHE_LAST_FAILURE);
        }

        $minutesAgo = $this->minutesSinceIsoPrefix($lastRun);
        $successMinutesAgo = $this->minutesSinceIsoPrefix($lastSuccess);
        $bootstrapFailMinutesAgo = $this->minutesSinceIsoPrefix($lastBootstrapFailure);

        $recentBootstrapFailure = $bootstrapFailMinutesAgo !== null
            && $bootstrapFailMinutesAgo <= self::RUNNER_STALE_MINUTES;
        $recentSuccess = $successMinutesAgo !== null
            && $successMinutesAgo <= self::RUNNER_STALE_MINUTES;
        $schedulerHeartbeat = $minutesAgo !== null && $minutesAgo <= self::RUNNER_STALE_MINUTES;

        $runnerHealthy = $recentSuccess && ! $recentBootstrapFailure;
        $connectionBootstrapOk = ! $recentBootstrapFailure;

        if ($recentBootstrapFailure) {
            $status = 'connection_failed';
            $label = 'Publishing connection failed';
        } elseif ($runnerHealthy) {
            $status = 'healthy';
            $label = 'Runner healthy';
        } elseif ($schedulerHeartbeat) {
            $status = 'degraded';
            $label = 'Scheduler heartbeat only — no successful due scan';
        } else {
            $status = 'stale';
            $label = 'Runner stale / unavailable';
        }

        return [
            'waiting' => $waiting,
            'processing' => $processing,
            'failed' => $failed,
            'retrying' => $retrying,
            'stuck_publishing' => $stuck,
            'runner_healthy' => $runnerHealthy,
            'connection_bootstrap_ok' => $connectionBootstrapOk,
            'runner_status' => $status,
            'runner_status_label' => $label,
            'runner_last_ran_minutes_ago' => $minutesAgo,
            'last_worker_run' => $lastRun,
            'last_success' => $lastSuccess,
            'last_failure' => $lastFailure,
            'last_bootstrap_failure' => $lastBootstrapFailure,
            'health_connection_id' => $scopeId,
            'health_hash_id' => $resolvedHash,
        ];
    }

    public function rememberWorkerRun(?int $connectionId = null): void
    {
        $payload = now()->toIso8601String();
        Cache::put($this->scopedKey(self::CACHE_LAST_WORKER_RUN, $connectionId), $payload, now()->addDays(7));
        // Keep legacy key for older readers during rollout.
        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_LAST_WORKER_RUN, $payload, now()->addDays(7));
        }
    }

    public function rememberSuccess(int $count = 1, ?int $connectionId = null): void
    {
        $payload = now()->toIso8601String().'|count='.$count;
        if ($connectionId !== null && $connectionId > 0) {
            $payload .= '|connection_id='.$connectionId;
        }

        Cache::put($this->scopedKey(self::CACHE_LAST_SUCCESS, $connectionId), $payload, now()->addDays(7));
        Cache::forget($this->scopedKey(self::CACHE_LAST_BOOTSTRAP_FAILURE, $connectionId));

        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_LAST_SUCCESS, $payload, now()->addDays(7));
            Cache::forget(self::CACHE_LAST_BOOTSTRAP_FAILURE);
        }
    }

    public function rememberFailure(string $message, ?int $connectionId = null): void
    {
        $payload = now()->toIso8601String().'|'.mb_substr($message, 0, 200);
        Cache::put($this->scopedKey(self::CACHE_LAST_FAILURE, $connectionId), $payload, now()->addDays(7));
        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_LAST_FAILURE, $payload, now()->addDays(7));
        }
    }

    public function rememberBootstrapFailure(string $message, ?int $connectionId = null): void
    {
        $payload = now()->toIso8601String().'|'.mb_substr($message, 0, 200);
        if ($connectionId !== null && $connectionId > 0) {
            $payload .= '|connection_id='.$connectionId;
        }

        Cache::put($this->scopedKey(self::CACHE_LAST_BOOTSTRAP_FAILURE, $connectionId), $payload, now()->addDays(7));

        // Never write unscoped global bootstrap failure from a known connection —
        // that was how stale connection_id=2 painted other tenants unhealthy.
        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_LAST_BOOTSTRAP_FAILURE, $payload, now()->addDays(7));
        }
    }

    public function scopedKey(string $base, ?int $connectionId): string
    {
        if ($connectionId === null || $connectionId <= 0) {
            return $base;
        }

        return $base.'.'.$connectionId;
    }

    private function cacheString(string $key): ?string
    {
        $value = Cache::get($key);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function minutesSinceIsoPrefix(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $iso = explode('|', $value, 2)[0];

        try {
            return (int) abs(now()->diffInMinutes(\Carbon\Carbon::parse($iso)));
        } catch (\Throwable) {
            return null;
        }
    }
}
