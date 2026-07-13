<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectArchive;
use App\Addons\SeoContentAi\Models\SeoProjectArchiveItem;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoProjectArchiveService
{
    /**
     * @return array{archived: int, tasks_removed: int}
     */
    public function archiveProject(SeoProject $project, int $archivedByUserId, ?string $note = null): array
    {
        if ($archivedByUserId <= 0) {
            throw new RuntimeException(__('seo-content-ai::filament.projects.archive_failed'));
        }

        $note = trim((string) $note);
        $note = $note !== '' ? mb_substr($note, 0, 500) : null;

        return DB::connection($project->getConnectionName())->transaction(
            function () use ($project, $archivedByUserId, $note): array {
                /** @var SeoProject|null $lockedProject */
                $lockedProject = SeoProject::query()
                    ->whereKey($project->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedProject instanceof SeoProject) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.archive_failed'));
                }

                $activeTasks = $lockedProject->tasks()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $articleIds = $activeTasks
                    ->map(static fn (SeoProjectTask $task): int => (int) ($task->article_id ?? 0))
                    ->filter(static fn (int $articleId): bool => $articleId > 0)
                    ->unique()
                    ->values();

                if ($articleIds->isEmpty()) {
                    throw new RuntimeException(__('seo-content-ai::filament.projects.archive_no_active_articles'));
                }

                $archive = SeoProjectArchive::query()->create([
                    'project_id' => (int) $lockedProject->getKey(),
                    'archived_by' => $archivedByUserId,
                    'note' => $note,
                    'articles_count' => $articleIds->count(),
                ]);

                foreach ($articleIds as $articleId) {
                    SeoProjectArchiveItem::query()->create([
                        'seo_project_archive_id' => (int) $archive->getKey(),
                        'article_id' => $articleId,
                    ]);
                }

                $tasksRemoved = (int) $lockedProject->tasks()->delete();

                $lockedProject->update([
                    'total_tasks' => 0,
                    'status' => SeoProject::STATUS_MANUAL,
                ]);

                return [
                    'archived' => $articleIds->count(),
                    'tasks_removed' => $tasksRemoved,
                ];
            },
        );
    }

    /**
     * @return Collection<int, SeoProjectArchive>
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
}
