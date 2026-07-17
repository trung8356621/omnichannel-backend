<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoContentArchiveItem;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectArchiveItem;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\WordPressPermalinkBuilder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoProjectArchiveService
{
    /**
     * Archive toàn bộ bài có article_id: ghi kho lưu trữ + set flag + xóa task khỏi project tháng.
     *
     * @return array{archived: int, tasks_removed: int}
     */
    public function archiveProject(SeoProject $project, int $archivedByUserId, ?string $note = null): array
    {
        if ($archivedByUserId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_failed'));
        }

        if ($project->isArchive()) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_source_is_archive'));
        }

        $note = $this->normalizeNote($note);

        return DB::connection($project->getConnectionName())->transaction(
            function () use ($project, $archivedByUserId, $note): array {
                $lockedProject = $this->lockMonthlyProject($project);
                $siteId = (int) ($lockedProject->site_id ?? 0);
                if ($siteId <= 0) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.domain_required'));
                }

                $activeTasks = $lockedProject->tasks()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $tasksWithArticles = $activeTasks
                    ->filter(static fn (SeoProjectTask $task): bool => (int) ($task->article_id ?? 0) > 0)
                    ->values();

                if ($tasksWithArticles->isEmpty()) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.archive_no_active_articles'));
                }

                $now = now();
                $archived = 0;

                foreach ($tasksWithArticles as $task) {
                    $this->persistArchiveItemFromTask(
                        $task,
                        $lockedProject,
                        $siteId,
                        $archivedByUserId,
                        $note,
                        $now,
                    );
                    $archived++;
                }

                $tasksRemoved = (int) $lockedProject->tasks()->delete();

                $lockedProject->update([
                    'total_tasks' => 0,
                    'status' => SeoProject::STATUS_MANUAL,
                ]);

                return [
                    'archived' => $archived,
                    'tasks_removed' => $tasksRemoved,
                ];
            },
        );
    }

    /**
     * Archive một/nhiều task: ghi kho + flag + xóa task khỏi project tháng.
     *
     * @param  list<int>  $taskIds
     * @param  array<int, int>  $articleIdByTaskId  Fallback khi task.article_id trống (vd. lấy từ run item).
     * @return array{archived: int}
     */
    public function archiveTasks(
        SeoProject $project,
        array $taskIds,
        int $archivedByUserId,
        ?string $note = null,
        array $articleIdByTaskId = [],
    ): array {
        if ($archivedByUserId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_failed'));
        }

        if ($project->isArchive()) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_source_is_archive'));
        }

        $taskIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($taskIds === []) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_no_active_articles'));
        }

        $articleIdByTaskId = array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $articleIdByTaskId),
            static fn (int $id): bool => $id > 0,
        );

        $note = $this->normalizeNote($note);

        return DB::connection($project->getConnectionName())->transaction(
            function () use ($project, $taskIds, $archivedByUserId, $note, $articleIdByTaskId): array {
                $lockedProject = $this->lockMonthlyProject($project);
                $siteId = (int) ($lockedProject->site_id ?? 0);
                if ($siteId <= 0) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.domain_required'));
                }

                $tasks = $lockedProject->tasks()
                    ->whereIn('id', $taskIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $now = now();
                $archived = 0;
                /** @var array<int, true> $handledTaskIds */
                $handledTaskIds = [];

                foreach ($tasks as $task) {
                    $articleId = (int) ($task->article_id ?? 0);
                    if ($articleId <= 0) {
                        $articleId = (int) ($articleIdByTaskId[(int) $task->id] ?? 0);
                    }

                    if ($articleId <= 0) {
                        continue;
                    }

                    $task->article_id = $articleId;

                    $this->persistArchiveItemFromTask(
                        $task,
                        $lockedProject,
                        $siteId,
                        $archivedByUserId,
                        $note,
                        $now,
                    );
                    $handledTaskIds[(int) $task->id] = true;
                    $task->delete();
                    $archived++;
                }

                // Task đã mất khỏi project nhưng run item vẫn còn article_id.
                foreach ($taskIds as $taskId) {
                    if (isset($handledTaskIds[$taskId])) {
                        continue;
                    }

                    $articleId = (int) ($articleIdByTaskId[$taskId] ?? 0);
                    if ($articleId <= 0) {
                        continue;
                    }

                    $linkedTask = $lockedProject->tasks()
                        ->where('article_id', $articleId)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();

                    if ($linkedTask instanceof SeoProjectTask) {
                        $this->persistArchiveItemFromTask(
                            $linkedTask,
                            $lockedProject,
                            $siteId,
                            $archivedByUserId,
                            $note,
                            $now,
                        );
                        $linkedTask->delete();
                    } else {
                        $this->persistArchiveItemFromArticle(
                            $siteId,
                            $articleId,
                            (int) $lockedProject->getKey(),
                            $archivedByUserId,
                            $note,
                            $now,
                        );
                    }

                    $handledTaskIds[$taskId] = true;
                    $archived++;
                }

                if ($archived === 0) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.archive_no_active_articles'));
                }

                $lockedProject->syncTotalTasksCounter();

                return [
                    'archived' => $archived,
                ];
            },
        );
    }

    public function countForSite(int $siteId): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        return (int) SeoContentArchiveItem::query()
            ->where('site_id', $siteId)
            ->count();
    }

    /**
     * Hủy archive: xóa kho + gỡ cờ bài + dọn task/content-project còn sót.
     *
     * @return array{article_id: int}
     */
    public function unarchiveItem(int $archiveItemId, int $siteId, int $requestedByUserId): array
    {
        if ($archiveItemId <= 0 || $siteId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.unarchive_item_not_found'));
        }

        if ($requestedByUserId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.unarchive_failed'));
        }

        return DB::connection((new SeoContentArchiveItem)->getConnectionName())->transaction(
            function () use ($archiveItemId, $siteId): array {
                /** @var SeoContentArchiveItem|null $item */
                $item = SeoContentArchiveItem::query()
                    ->whereKey($archiveItemId)
                    ->where('site_id', $siteId)
                    ->lockForUpdate()
                    ->first();

                if (! $item instanceof SeoContentArchiveItem) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.unarchive_item_not_found'));
                }

                $articleId = (int) ($item->article_id ?? 0);
                if ($articleId <= 0) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.unarchive_item_not_found'));
                }

                $item->delete();

                SeoArticle::query()
                    ->whereKey($articleId)
                    ->update([
                        'content_archived_at' => null,
                        'content_archived_by' => null,
                    ]);

                SeoProjectTask::query()
                    ->where('article_id', $articleId)
                    ->delete();

                SeoProjectArchiveItem::query()
                    ->where('article_id', $articleId)
                    ->delete();

                return [
                    'article_id' => $articleId,
                ];
            },
        );
    }

    /**
     * Dashboard kho lưu trữ — group theo ngày hoàn tất (giống Articles Reviewed).
     *
     * @return array{
     *     groups: list<array{
     *         date: string,
     *         date_label: string,
     *         month_key: string,
     *         month_label: string,
     *         count: int,
     *         articles: list<array{
     *             id: int,
     *             task_id: int,
     *             title: string,
     *             author: string,
     *             connected_at: string,
     *             connected_label: string,
     *             completed_time: string,
     *             edit_url: string,
     *             view_url: string|null
     *         }>
     *     }>,
     *     month_options: list<array{value: string, label: string}>
     * }
     */
    public function buildGroupedDashboard(int $siteId): array
    {
        if ($siteId <= 0) {
            return [
                'groups' => [],
                'month_options' => [],
            ];
        }

        $items = SeoContentArchiveItem::query()
            ->where('site_id', $siteId)
            ->with([
                'article:id,title,slug,user_id,site_id',
                'article.user:id,name',
                'article.site',
                'article.articleMetas',
            ])
            ->orderByDesc('completed_at')
            ->orderByDesc('archived_at')
            ->orderByDesc('id')
            ->get();

        /** @var array<string, array{date: string, date_label: string, month_key: string, month_label: string, count: int, articles: list<array<string, mixed>>}> $grouped */
        $grouped = [];
        /** @var array<string, string> $monthLabels */
        $monthLabels = [];

        foreach ($items as $item) {
            if (! $item instanceof SeoContentArchiveItem) {
                continue;
            }

            $completedRaw = $item->completed_at ?? $item->archived_at ?? $item->connected_at ?? $item->created_at;
            if ($completedRaw === null) {
                continue;
            }

            $completedAt = $completedRaw instanceof Carbon
                ? $completedRaw
                : Carbon::parse((string) $completedRaw);

            $dateKey = $completedAt->toDateString();
            $monthKey = $completedAt->format('Y-m');
            $monthLabels[$monthKey] = $completedAt->format('m/Y');

            if (! isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [
                    'date' => $dateKey,
                    'date_label' => $completedAt->translatedFormat('d/m/Y'),
                    'month_key' => $monthKey,
                    'month_label' => $monthLabels[$monthKey],
                    'count' => 0,
                    'articles' => [],
                ];
            }

            $article = $item->article;
            $title = trim((string) ($article?->title ?? $item->source_content ?? ''));
            if ($title === '') {
                $title = 'Article #'.(int) ($item->article_id ?? 0);
            }

            $author = trim((string) ($article?->user?->name ?? ''));
            $connectedAt = $item->connected_at instanceof Carbon
                ? $item->connected_at
                : ($item->connected_at !== null ? Carbon::parse((string) $item->connected_at) : null);

            $grouped[$dateKey]['articles'][] = [
                'id' => (int) ($item->article_id ?? 0),
                'task_id' => (int) $item->id,
                'title' => $title,
                'author' => $author !== '' ? $author : '—',
                'connected_at' => $connectedAt?->toDateString() ?? '',
                'connected_label' => $connectedAt?->format('d/m/Y H:i') ?? '—',
                'completed_time' => $completedAt->format('H:i'),
                'edit_url' => $article !== null
                    ? ArticleResource::getUrl('edit', ['record' => $article])
                    : '#',
                'view_url' => $article !== null
                    ? $this->resolveArticleViewUrl($article)
                    : null,
            ];
            $grouped[$dateKey]['count']++;
        }

        krsort($monthLabels);
        $monthOptions = [];
        foreach ($monthLabels as $value => $label) {
            $monthOptions[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return [
            'groups' => array_values($grouped),
            'month_options' => $monthOptions,
        ];
    }

    /**
     * @deprecated Legacy batch loader.
     *
     * @return Collection<int, \App\Addons\SeoContentAi\Models\SeoProjectArchive>
     */
    public function batchesForProject(SeoProject $project): Collection
    {
        return $project->archives()
            ->with([
                'archivedByUser:id,name',
                'items.article:id,title,status,user_id,created_at',
                'items.article.user:id,name',
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    private function lockMonthlyProject(SeoProject $project): SeoProject
    {
        /** @var SeoProject|null $lockedProject */
        $lockedProject = SeoProject::query()
            ->whereKey($project->getKey())
            ->lockForUpdate()
            ->first();

        if (! $lockedProject instanceof SeoProject || $lockedProject->isArchive()) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_failed'));
        }

        return $lockedProject;
    }

    private function persistArchiveItemFromTask(
        SeoProjectTask $task,
        SeoProject $fromProject,
        int $siteId,
        int $archivedByUserId,
        ?string $note,
        Carbon $now,
    ): void {
        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId <= 0) {
            return;
        }

        $completedAt = $task->completed_at;
        if ($completedAt === null && (string) $task->status === SeoProjectTask::STATUS_COMPLETED) {
            $completedAt = $task->updated_at ?? $now;
        }

        $this->upsertArchiveItem(
            siteId: $siteId,
            articleId: $articleId,
            fromProjectId: (int) $fromProject->getKey(),
            archivedByUserId: $archivedByUserId,
            connectedAt: $task->connected_at ?? $now,
            completedAt: $completedAt ?? $now,
            archivedAt: $now,
            note: $note,
            sourceContent: (string) ($task->source_content ?? ''),
            taskType: $task->type !== null ? (string) $task->type : null,
        );
    }

    private function persistArchiveItemFromArticle(
        int $siteId,
        int $articleId,
        int $fromProjectId,
        int $archivedByUserId,
        ?string $note,
        Carbon $now,
    ): void {
        if ($articleId <= 0) {
            return;
        }

        $article = SeoArticle::query()->find($articleId);
        $sourceContent = trim((string) ($article?->title ?? ''));

        $this->upsertArchiveItem(
            siteId: $siteId,
            articleId: $articleId,
            fromProjectId: $fromProjectId > 0 ? $fromProjectId : null,
            archivedByUserId: $archivedByUserId,
            connectedAt: $now,
            completedAt: $now,
            archivedAt: $now,
            note: $note,
            sourceContent: $sourceContent,
            taskType: null,
        );
    }

    private function upsertArchiveItem(
        int $siteId,
        int $articleId,
        ?int $fromProjectId,
        int $archivedByUserId,
        mixed $connectedAt,
        mixed $completedAt,
        mixed $archivedAt,
        ?string $note,
        string $sourceContent,
        ?string $taskType,
    ): void {
        SeoContentArchiveItem::query()->updateOrCreate(
            ['article_id' => $articleId],
            [
                'site_id' => $siteId,
                'from_project_id' => $fromProjectId,
                'archived_by' => $archivedByUserId,
                'connected_at' => $connectedAt,
                'completed_at' => $completedAt,
                'archived_at' => $archivedAt,
                'note' => $note,
                'source_content' => $sourceContent !== ''
                    ? mb_substr($sourceContent, 0, 500)
                    : null,
                'task_type' => $taskType,
            ],
        );

        SeoArticle::query()
            ->whereKey($articleId)
            ->update([
                'content_archived_at' => $archivedAt,
                'content_archived_by' => $archivedByUserId,
            ]);
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note !== '' ? mb_substr($note, 0, 500) : null;
    }

    private function resolveArticleViewUrl(SeoArticle $article): ?string
    {
        $article->loadMissing('site', 'articleMetas');

        $cached = trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));
        $slug = trim((string) ($article->slug ?? ''));

        $resolved = app(WordPressPermalinkBuilder::class)->resolve($article, $cached, $slug !== '' ? $slug : null);
        if ($resolved !== '') {
            return $resolved;
        }

        return null;
    }
}
