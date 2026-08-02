<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStatusBadgePresenter;
use App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueueStateClassifier;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Illuminate\Support\Collection;

/**
 * Publishing Queue read model — Summary ≡ List via PublishingQueueStateClassifier.
 */
final class ContentProjectPublishingQueueReadModel
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{stats: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function forProject(SeoProject $project, array $filters = []): array
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->inPublishingQueue()
            ->with([
                'article.articleMetas' => static fn ($q) => $q->where('meta_key', 'wp_featured_image_url'),
            ])
            ->orderBy('id')
            ->get();

        return $this->buildPayload($tasks, $filters, includeProjectMeta: false);
    }

    /**
     * Independent Publishing Queue hub — all accessible sites, optionally scoped to one project.
     *
     * @param  array<string, mixed>  $filters
     * @return array{stats: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function forHub(?int $projectId, array $filters = []): array
    {
        if ($projectId !== null && $projectId > 0) {
            $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()->find($projectId);
            if ($project instanceof SeoProject) {
                return $this->forProject($project, $filters);
            }

            return ['stats' => PublishingQueueStateClassifier::countSummary([]), 'rows' => []];
        }

        $siteIds = SeoAccessControl::accessibleSiteIds();
        $projectQuery = SeoProject::query();
        if ($siteIds !== []) {
            $projectQuery->whereIn('site_id', $siteIds);
        }
        $projectIds = $projectQuery->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        if ($projectIds === []) {
            return ['stats' => PublishingQueueStateClassifier::countSummary([]), 'rows' => []];
        }

        $tasks = SeoProjectTask::query()
            ->whereIn('project_id', $projectIds)
            ->inPublishingQueue()
            ->with([
                'article.articleMetas' => static fn ($q) => $q->where('meta_key', 'wp_featured_image_url'),
                'project',
            ])
            ->orderBy('id')
            ->get();

        return $this->buildPayload($tasks, $filters, includeProjectMeta: true);
    }

    /**
     * @param  Collection<int, SeoProjectTask>  $tasks
     * @param  array<string, mixed>  $filters
     * @return array{stats: array<string, int>, rows: list<array<string, mixed>>}
     */
    private function buildPayload(Collection $tasks, array $filters, bool $includeProjectMeta): array
    {
        $rows = $tasks->map(function (SeoProjectTask $task) use ($includeProjectMeta): array {
            $article = $task->article;
            $queue = (string) ($task->publish_queue_status ?? 'none');
            $title = (string) ($article?->title ?? $task->keyword ?? ('#'.$task->getKey()));
            $slug = (string) ($article?->slug ?? '');
            $keyword = trim((string) ($task->keyword ?? ''));
            if ($keyword === '') {
                $keyword = $slug;
            }

            $thumbnailUrl = null;
            if ($article !== null && $article->relationLoaded('articleMetas')) {
                $raw = trim((string) (
                    $article->articleMetas->firstWhere('meta_key', 'wp_featured_image_url')?->meta_value ?? ''
                ));
                $thumbnailUrl = $raw !== '' ? $raw : null;
            }

            $queuedAt = $task->publishing_queued_at;
            $lastActivity = $task->scheduled_publish_at?->diffForHumans()
                ?? $queuedAt?->diffForHumans()
                ?? '—';
            $lastActivityFull = $task->scheduled_publish_at?->toDateTimeString()
                ?? $queuedAt?->toDateTimeString()
                ?? '';

            $row = [
                'task_id' => (int) $task->getKey(),
                'project_id' => (int) ($task->project_id ?? 0),
                'article_id' => (int) ($task->article_id ?? 0) ?: null,
                'primary_label' => $title,
                'title' => $title,
                'keyword' => $keyword !== '' ? $keyword : '—',
                'slug' => $slug,
                'thumbnail_url' => $thumbnailUrl,
                'has_featured_image' => $thumbnailUrl !== null,
                'scheduled_publish_at' => $task->scheduled_publish_at,
                'scheduled_raw' => $task->scheduled_publish_at?->toIso8601String(),
                'scheduled_at' => $task->scheduled_publish_at?->format('d/m/Y H:i') ?? '—',
                'publish_queue_status' => $queue,
                'queue_status' => $queue,
                'publish_published_at' => $task->publish_published_at?->toIso8601String(),
                'lifecycle' => '',
                'last_publish_error' => (string) ($task->last_publish_error ?? ''),
                'message' => (string) ($task->last_publish_error ?? ''),
                'publishing_queued_at' => $queuedAt?->toIso8601String(),
                'last_activity' => $lastActivity,
                'last_activity_full' => $lastActivityFull,
                'is_recently_completed' => false,
                'article_edit_url' => $task->article_id
                    ? ArticleResource::getUrl('edit', ['record' => (int) $task->article_id])
                    : null,
            ];

            if ($includeProjectMeta) {
                $project = $task->relationLoaded('project') ? $task->project : null;
                $row['project_name'] = $project instanceof SeoProject ? (string) $project->name : '';
                $row['project_url'] = $project instanceof SeoProject
                    ? SeoProjectResource::getProjectWorkspaceUrl($project)
                    : null;
                if ($row['project_name'] !== '') {
                    $row['type_label'] = $row['project_name'];
                }
            }

            $classified = PublishingQueueStateClassifier::classify($row);
            $row['publish_state'] = $classified['state'];
            $row['publish_state_label'] = $classified['label'];
            $row['publish_badge'] = ContentProjectStatusBadgePresenter::publishQueueState((string) $classified['state']);

            return $row;
        })->values();

        $state = trim((string) ($filters['state'] ?? ''));
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        $filtered = $rows->filter(static function (array $row) use ($state, $search): bool {
            if ($state !== '' && ! PublishingQueueStateClassifier::matchesFilter($row, $state)) {
                return false;
            }
            if ($search !== '') {
                $hay = strtolower($row['title'].' '.$row['slug'].' '.$row['task_id'].' '.($row['project_name'] ?? ''));
                if (! str_contains($hay, $search)) {
                    return false;
                }
            }

            return true;
        })->values();

        /** @var list<array<string, mixed>> $list */
        $list = $filtered->all();

        return [
            'stats' => PublishingQueueStateClassifier::countSummary($rows->all()),
            'rows' => $list,
        ];
    }
}
