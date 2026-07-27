<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing Queue Health — cross-project / scoped.
 */
final class ContentProjectQueueHealthService
{
    public const CACHE_LAST_WORKER_RUN = 'seo.content_project.publish_queue.last_worker_run';

    public const CACHE_LAST_SUCCESS = 'seo.content_project.publish_queue.last_success';

    public const CACHE_LAST_FAILURE = 'seo.content_project.publish_queue.last_failure';

    /**
     * @param  list<int>|null  $siteIds
     * @return array{
     *     waiting: int,
     *     processing: int,
     *     failed: int,
     *     retrying: int,
     *     last_worker_run: string|null,
     *     last_success: string|null,
     *     last_failure: string|null,
     * }
     */
    public function snapshot(?array $siteIds = null): array
    {
        $waiting = 0;
        $processing = 0;
        $failed = 0;
        $retrying = 0;

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            $base = SeoProjectTask::query()
                ->active()
                ->whereHas('project', static function ($q) use ($siteIds): void {
                    $q->whereNull('archived_at');
                    if (is_array($siteIds) && $siteIds !== []) {
                        $q->whereIn('site_id', $siteIds);
                    }
                });

            $waiting = (int) (clone $base)->where('publish_queue_status', ContentProjectPublishQueueStatus::Waiting->value)->count();
            $processing = (int) (clone $base)->where('publish_queue_status', ContentProjectPublishQueueStatus::Processing->value)->count();
            $failed = (int) (clone $base)->where('publish_queue_status', ContentProjectPublishQueueStatus::Failed->value)->count();
            $retrying = (int) (clone $base)->where('publish_queue_status', ContentProjectPublishQueueStatus::Retrying->value)->count();
        }

        return [
            'waiting' => $waiting,
            'processing' => $processing,
            'failed' => $failed,
            'retrying' => $retrying,
            'last_worker_run' => $this->cacheString(self::CACHE_LAST_WORKER_RUN),
            'last_success' => $this->cacheString(self::CACHE_LAST_SUCCESS),
            'last_failure' => $this->cacheString(self::CACHE_LAST_FAILURE),
        ];
    }

    public function rememberWorkerRun(): void
    {
        Cache::put(self::CACHE_LAST_WORKER_RUN, now()->toIso8601String(), now()->addDays(7));
    }

    public function rememberSuccess(int $count = 1): void
    {
        Cache::put(self::CACHE_LAST_SUCCESS, now()->toIso8601String().'|count='.$count, now()->addDays(7));
    }

    public function rememberFailure(string $message): void
    {
        Cache::put(
            self::CACHE_LAST_FAILURE,
            now()->toIso8601String().'|'.mb_substr($message, 0, 200),
            now()->addDays(7),
        );
    }

    private function cacheString(string $key): ?string
    {
        $value = Cache::get($key);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
