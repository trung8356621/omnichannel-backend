<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Jobs\AuditLinkStatusJob;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SeoQueueControlService
{
    private const AUDIT_JOB_MARKER = 'AuditLinkStatusJob';

    private const PAUSE_CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;

    private const WORKER_ACTIVE_SECONDS = 120;

    /**
     * @return array{
     *     paused: bool,
     *     pending_audit_jobs: int,
     *     running_audit_jobs: int,
     *     pending_default_jobs: int,
     *     pending_wp_sync_jobs: int,
     *     pending_work_total: int,
     *     worker_status: string,
     *     worker_active: bool,
     *     last_reserved_at: ?string,
     *     owner_id: int
     * }
     */
    public function statusForOwner(int $ownerId): array
    {
        if ($ownerId <= 0) {
            return $this->emptyStatus();
        }

        $siteIds = $this->siteIdsForOwner($ownerId);
        $jobs = $this->fetchAuditJobs();
        $auditJobs = $this->filterAuditJobsForSites($jobs, $siteIds);

        $now = now()->getTimestamp();
        $workerThreshold = $now - self::WORKER_ACTIVE_SECONDS;

        $pendingAudit = 0;
        $runningAudit = 0;
        $lastReservedAt = null;

        foreach ($auditJobs as $job) {
            $reservedAt = (int) ($job->reserved_at ?? 0);

            if ($reservedAt > 0) {
                $runningAudit++;

                if ($lastReservedAt === null || $reservedAt > $lastReservedAt) {
                    $lastReservedAt = $reservedAt;
                }

                continue;
            }

            $pendingAudit++;
        }

        $workerActivity = DB::connection($this->jobsConnection())
            ->table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '>=', $workerThreshold)
            ->exists();

        $pendingDefault = $this->countPendingQueueJobs($workerThreshold);
        $pendingWpSync = $this->countPendingWpSyncForOwner($ownerId);
        $pendingWorkTotal = $pendingAudit + $pendingDefault + $pendingWpSync;

        $workerStatus = match (true) {
            $workerActivity => 'running',
            $pendingWorkTotal > 0 => 'offline',
            default => 'idle',
        };

        return [
            'paused' => $this->isPausedForOwner($ownerId),
            'pending_audit_jobs' => $pendingAudit,
            'running_audit_jobs' => $runningAudit,
            'pending_default_jobs' => $pendingDefault,
            'pending_wp_sync_jobs' => $pendingWpSync,
            'pending_work_total' => $pendingWorkTotal,
            'worker_status' => $workerStatus,
            'worker_active' => $workerActivity,
            'last_reserved_at' => $lastReservedAt !== null
                ? now()->createFromTimestamp($lastReservedAt)->toDateTimeString()
                : null,
            'owner_id' => $ownerId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statusForCurrentOwner(): array
    {
        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();

        return $this->statusForOwner((int) $ownerId);
    }

    public function shouldShowOfflineAlertForCurrentOwner(): bool
    {
        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();

        return $this->shouldShowOfflineAlertForOwner((int) $ownerId);
    }

    public function shouldShowOfflineAlertForOwner(int $ownerId): bool
    {
        $status = $this->statusForOwner($ownerId);

        if ($status['worker_active'] ?? false) {
            return false;
        }

        return (int) ($status['pending_work_total'] ?? 0) > 0;
    }

    public function pauseForCurrentOwner(): void
    {
        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
        $this->pauseForOwner((int) $ownerId);
    }

    public function resumeForCurrentOwner(): void
    {
        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
        $this->resumeForOwner((int) $ownerId);
    }

    public function stopAuditJobsForCurrentOwner(): int
    {
        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();

        return $this->purgePendingAuditJobsForOwner((int) $ownerId);
    }

    public function pauseForOwner(int $ownerId): void
    {
        if ($ownerId <= 0) {
            return;
        }

        Cache::put($this->pauseCacheKey($ownerId), true, self::PAUSE_CACHE_TTL_SECONDS);
        $this->purgePendingAuditJobsForOwner($ownerId);
    }

    public function resumeForOwner(int $ownerId): void
    {
        if ($ownerId <= 0) {
            return;
        }

        Cache::forget($this->pauseCacheKey($ownerId));
    }

    public function isPausedForSite(int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        $ownerId = (int) (Site::query()->whereKey($siteId)->value('user_id') ?? 0);

        return $this->isPausedForOwner($ownerId);
    }

    public function isPausedForOwner(int $ownerId): bool
    {
        if ($ownerId <= 0) {
            return false;
        }

        return (bool) Cache::get($this->pauseCacheKey($ownerId), false);
    }

    public function purgePendingAuditJobsForOwner(int $ownerId): int
    {
        if ($ownerId <= 0) {
            return 0;
        }

        $siteIds = $this->siteIdsForOwner($ownerId);
        $deleted = 0;

        foreach ($this->fetchAuditJobs() as $job) {
            if ((int) ($job->reserved_at ?? 0) > 0) {
                continue;
            }

            $siteId = $this->extractSiteIdFromAuditPayload((string) ($job->payload ?? ''));
            if ($siteId === null || ! in_array($siteId, $siteIds, true)) {
                continue;
            }

            DB::connection($this->jobsConnection())
                ->table('jobs')
                ->where('id', $job->id)
                ->delete();

            $deleted++;
        }

        return $deleted;
    }

    public function isAuditLinkJobPayload(string $payload): bool
    {
        return str_contains($payload, self::AUDIT_JOB_MARKER)
            || str_contains($payload, AuditLinkStatusJob::class);
    }

    public function extractSiteIdFromAuditPayload(string $payload): ?int
    {
        if (! $this->isAuditLinkJobPayload($payload)) {
            return null;
        }

        if (preg_match('/s:\d+:"siteId";i:(\d+)/', $payload, $matches) === 1) {
            $siteId = (int) $matches[1];

            return $siteId > 0 ? $siteId : null;
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return null;
        }

        $command = $decoded['data']['command'] ?? null;
        if (! is_string($command) || $command === '') {
            return null;
        }

        $job = @unserialize($command, ['allowed_classes' => [AuditLinkStatusJob::class]]);
        if ($job instanceof AuditLinkStatusJob) {
            return $job->siteId > 0 ? $job->siteId : null;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function siteIdsForOwner(int $ownerId): array
    {
        return Site::query()
            ->where('user_id', $ownerId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function fetchAuditJobs(): Collection
    {
        return DB::connection($this->jobsConnection())
            ->table('jobs')
            ->select(['id', 'payload', 'reserved_at'])
            ->where('payload', 'like', '%'.self::AUDIT_JOB_MARKER.'%')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, object{id: int, payload: string, reserved_at: int|null}>  $jobs
     * @param  list<int>  $siteIds
     * @return list<object{id: int, payload: string, reserved_at: int|null}>
     */
    private function filterAuditJobsForSites(Collection $jobs, array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $filtered = [];

        foreach ($jobs as $job) {
            $siteId = $this->extractSiteIdFromAuditPayload((string) ($job->payload ?? ''));
            if ($siteId !== null && in_array($siteId, $siteIds, true)) {
                $filtered[] = $job;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStatus(): array
    {
        return [
            'paused' => false,
            'pending_audit_jobs' => 0,
            'running_audit_jobs' => 0,
            'pending_default_jobs' => 0,
            'pending_wp_sync_jobs' => 0,
            'pending_work_total' => 0,
            'worker_status' => 'idle',
            'worker_active' => false,
            'last_reserved_at' => null,
            'owner_id' => 0,
        ];
    }

    private function countPendingQueueJobs(int $workerThreshold): int
    {
        try {
            return (int) DB::connection($this->jobsConnection())
                ->table('jobs')
                ->where(function ($query) use ($workerThreshold): void {
                    $query->whereNull('reserved_at')
                        ->orWhere('reserved_at', '<', $workerThreshold);
                })
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function countPendingWpSyncForOwner(int $ownerId): int
    {
        if ($ownerId <= 0) {
            return 0;
        }

        $siteIds = $this->siteIdsForOwner($ownerId);
        if ($siteIds === []) {
            return 0;
        }

        try {
            return (int) DB::connection('omi_seo_ai')
                ->table('article_meta')
                ->join('articles', 'articles.id', '=', 'article_meta.article_id')
                ->where('article_meta.meta_key', ArticleWpSyncQueueService::META_KEY)
                ->whereIn('articles.site_id', $siteIds)
                ->where(function ($query): void {
                    $query
                        ->where('article_meta.meta_value', 'like', '%"status":"'.ArticleWpSyncQueueService::STATUS_PENDING.'"%')
                        ->orWhere('article_meta.meta_value', 'like', '%"status":"'.ArticleWpSyncQueueService::STATUS_PROCESSING.'"%');
                })
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function pauseCacheKey(int $ownerId): string
    {
        return 'seo_queue_audit_paused:'.$ownerId;
    }

    private function jobsConnection(): string
    {
        $connection = (string) config('queue.connections.'.config('queue.default').'.connection');

        return $connection !== '' ? $connection : (string) config('database.default');
    }

    private function defaultQueueName(): string
    {
        return (string) config('queue.connections.'.config('queue.default').'.queue', 'default');
    }
}
