<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregate dashboard stats.
 * Bucket semantics: ContentProjectItemDashboardBucketMapper (tested vs resolver fixtures).
 * SQL CASE below must stay equivalent to ContentProjectItemDashboardBucketMapper::fromRawRow().
 */
final class ContentProjectDashboardStatsService
{
    /**
     * @return array{
     *     total_items: int,
     *     waiting_ai: int,
     *     ai_running: int,
     *     waiting_review: int,
     *     approved: int,
     *     waiting_publish: int,
     *     published: int,
     *     failed: int,
     *     archived: int,
     * }
     */
    public function forProject(SeoProject $project): array
    {
        $projectId = (int) $project->getKey();
        if ($projectId <= 0) {
            return $this->empty();
        }

        $hasQueueStatus = Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status');
        $hasPublishPublishedAt = Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_published_at');
        $staleMinutes = max(1, (int) config('seo-content-ai.content_project.generation_task_stale_minutes', 0));
        if ($staleMinutes <= 0) {
            $staleMinutes = max(
                max(1, (int) config('seo-content-ai.content_project.run_item_stale_minutes', 30)),
                max(1, (int) config('seo-content-ai.content_project.heartbeat_stale_minutes', 20)),
            );
        }

        $notArchived = "t.archived_at IS NULL AND t.status != 'archived'";
        $isPublished = $hasPublishPublishedAt
            ? "(t.publish_published_at IS NOT NULL OR ".($hasQueueStatus ? "t.publish_queue_status = 'published' OR " : '')."LOWER(COALESCE(a.status,'')) IN ('published','publish'))"
            : ($hasQueueStatus
                ? "(t.publish_queue_status = 'published' OR LOWER(COALESCE(a.status,'')) IN ('published','publish'))"
                : "LOWER(COALESCE(a.status,'')) IN ('published','publish')");

        // Exclusive precedence mirrors ContentProjectItemDashboardBucketMapper::fromRawRow().
        $queueWaiting = $hasQueueStatus
            ? "SUM(CASE WHEN {$notArchived} AND NOT {$isPublished} AND (t.publish_queue_status IN ('waiting','processing','retrying') OR t.scheduled_publish_at IS NOT NULL) THEN 1 ELSE 0 END)"
            : "SUM(CASE WHEN {$notArchived} AND NOT {$isPublished} AND t.scheduled_publish_at IS NOT NULL THEN 1 ELSE 0 END)";

        $publishedExpr = "SUM(CASE WHEN {$notArchived} AND {$isPublished} THEN 1 ELSE 0 END)";

        $failedExpr = $hasQueueStatus
            ? "SUM(CASE WHEN {$notArchived} AND NOT {$isPublished}
                AND NOT (t.publish_queue_status IN ('waiting','processing','retrying') OR t.scheduled_publish_at IS NOT NULL)
                AND (t.status = 'failed' OR t.publish_queue_status = 'failed')
                THEN 1 ELSE 0 END)"
            : "SUM(CASE WHEN {$notArchived} AND NOT {$isPublished}
                AND t.scheduled_publish_at IS NULL
                AND t.status = 'failed'
                THEN 1 ELSE 0 END)";

        $waitingReviewExpr = "SUM(CASE WHEN {$notArchived} AND NOT {$isPublished}
            AND NOT (".($hasQueueStatus ? "t.publish_queue_status IN ('waiting','processing','retrying') OR " : '')."t.scheduled_publish_at IS NOT NULL)
            AND t.status != 'failed'
            AND ".($hasQueueStatus ? "COALESCE(t.publish_queue_status,'none') != 'failed' AND " : '')."
            (t.status = 'reviewing' OR (t.status = 'completed' AND COALESCE(a.review_status,'') != 'approved'))
            THEN 1 ELSE 0 END)";

        $row = DB::connection('omi_seo_ai')->selectOne("
            SELECT
                COUNT(*) AS total_items,
                SUM(CASE WHEN {$notArchived} AND NOT {$isPublished}
                    AND NOT (".($hasQueueStatus ? "t.publish_queue_status IN ('waiting','processing','retrying') OR " : '')."t.scheduled_publish_at IS NOT NULL)
                    AND t.status = 'pending' THEN 1 ELSE 0 END) AS waiting_ai,
                SUM(CASE WHEN {$notArchived} AND NOT {$isPublished}
                    AND t.status = 'writing'
                    AND t.updated_at >= DATE_SUB(NOW(), INTERVAL {$staleMinutes} MINUTE)
                    THEN 1 ELSE 0 END) AS ai_running,
                {$waitingReviewExpr} AS waiting_review,
                SUM(CASE WHEN {$notArchived} AND NOT {$isPublished}
                    AND t.status = 'completed' AND a.review_status = 'approved'
                    AND t.scheduled_publish_at IS NULL
                    AND ".($hasQueueStatus ? "COALESCE(t.publish_queue_status,'none') IN ('none','cancelled','skipped')" : '1=1')."
                    THEN 1 ELSE 0 END) AS approved,
                {$queueWaiting} AS waiting_publish,
                {$publishedExpr} AS published,
                {$failedExpr} AS failed,
                SUM(CASE WHEN t.archived_at IS NOT NULL OR t.status = 'archived' THEN 1 ELSE 0 END) AS archived
            FROM seo_project_tasks t
            LEFT JOIN articles a ON a.id = t.article_id AND a.deleted_at IS NULL
            WHERE t.project_id = ?
              AND t.deleted_at IS NULL
        ", [$projectId]);

        $aiRunningRuns = (int) SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->count();

        return [
            'total_items' => (int) ($row->total_items ?? 0),
            'waiting_ai' => (int) ($row->waiting_ai ?? 0),
            'ai_running' => (int) ($row->ai_running ?? 0),
            'waiting_review' => (int) ($row->waiting_review ?? 0),
            'approved' => (int) ($row->approved ?? 0),
            'waiting_publish' => (int) ($row->waiting_publish ?? 0),
            'published' => (int) ($row->published ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'archived' => (int) ($row->archived ?? 0),
            'ai_runs_active' => $aiRunningRuns,
        ];
    }

    /**
     * @return array{
     *     total_items: int,
     *     waiting_ai: int,
     *     ai_running: int,
     *     waiting_review: int,
     *     approved: int,
     *     waiting_publish: int,
     *     published: int,
     *     failed: int,
     *     archived: int,
     *     ai_runs_active: int,
     * }
     */
    private function empty(): array
    {
        return [
            'total_items' => 0,
            'waiting_ai' => 0,
            'ai_running' => 0,
            'waiting_review' => 0,
            'approved' => 0,
            'waiting_publish' => 0,
            'published' => 0,
            'failed' => 0,
            'archived' => 0,
            'ai_runs_active' => 0,
        ];
    }
}
