<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Operations;

use App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site health snapshot — read-only placeholders for WP reachability.
 */
final class ContentProjectSiteHealthService
{
    /**
     * @param  list<int>  $siteIds  accessible site IDs
     * @return list<array{
     *     site_id: int,
     *     waiting_articles: int,
     *     publishing: int,
     *     publish_failed: int,
     *     last_publish: string|null,
     *     last_sync: string|null,
     *     wp_reachable: string,
     *     token_ok: string,
     * }>
     */
    public function snapshot(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $hasQueue = Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status');
        $hasPublishedAt = Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_published_at');

        $out = [];
        foreach ($siteIds as $siteId) {
            $siteId = (int) $siteId;
            if ($siteId <= 0) {
                continue;
            }

            $taskBase = SeoProjectTask::query()
                ->where('site_id', $siteId)
                ->active();

            $waiting = (int) (clone $taskBase)
                ->whereIn('status', [SeoProjectTask::STATUS_PENDING, SeoProjectTask::STATUS_WRITING])
                ->count();

            $publishing = 0;
            $publishFailed = 0;
            if ($hasQueue) {
                $publishing = (int) (clone $taskBase)
                    ->whereIn('publish_queue_status', [
                        ContentProjectPublishQueueStatus::Waiting->value,
                        ContentProjectPublishQueueStatus::Processing->value,
                        ContentProjectPublishQueueStatus::Retrying->value,
                    ])
                    ->count();
                $publishFailed = (int) (clone $taskBase)
                    ->where('publish_queue_status', ContentProjectPublishQueueStatus::Failed->value)
                    ->count();
            }

            $lastPublish = null;
            if ($hasPublishedAt) {
                $lastPublish = (clone $taskBase)
                    ->whereNotNull('publish_published_at')
                    ->max('publish_published_at');
            }

            $lastSync = SeoArticle::query()
                ->where('site_id', $siteId)
                ->max('last_synced_at');

            $out[] = [
                'site_id' => $siteId,
                'waiting_articles' => $waiting,
                'publishing' => $publishing,
                'publish_failed' => $publishFailed,
                'last_publish' => $lastPublish !== null ? (string) $lastPublish : null,
                'last_sync' => $lastSync !== null ? (string) $lastSync : null,
                'wp_reachable' => 'unknown',
                'token_ok' => 'unknown',
            ];
        }

        return $out;
    }
}
