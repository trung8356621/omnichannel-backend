<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Domain assignment: SEO audit / article → Content Project task.
 * Không Filament notification; không WordPress outbound.
 */
final class SeoIssueProjectTaskAssignmentService
{
    public function __construct(
        private readonly SeoAnalyzerService $analyzer,
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, mixed>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public function assignFromFormData(Collection $records, int $projectId, array $data, bool $dryRun = false): array
    {
        return $this->assignArticles(
            $records,
            $projectId,
            $this->normalizeAssignTaskType($data['type'] ?? null),
            is_string($data['rewrite_mode'] ?? null) ? $data['rewrite_mode'] : null,
            is_string($data['rewrite_notes'] ?? null) ? $data['rewrite_notes'] : null,
            $dryRun,
        );
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public function assignArticles(
        Collection $records,
        int $projectId,
        string $taskType = SeoProjectTask::TYPE_REWRITE,
        ?string $rewriteMode = null,
        ?string $rewriteNotes = null,
        bool $dryRun = false,
    ): array {
        $empty = static fn (int $overflow): array => [
            'added' => 0,
            'duplicate' => 0,
            'overflow' => $overflow,
            'domain_mismatch' => 0,
            'already_in_project' => 0,
        ];

        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return $empty($records->count());
        }

        if (! $project->isExecutionMonthOpen()) {
            return $empty($records->count());
        }

        $records = $records
            ->filter(static fn (mixed $record): bool => $record instanceof SeoArticle)
            ->values();

        $added = 0;
        $duplicate = 0;
        $overflow = 0;
        $domainMismatch = 0;
        $alreadyInProject = 0;
        $projectSiteId = (int) ($project->site_id ?? 0);
        $targetProjectId = (int) $project->id;
        $normalizedTaskType = $this->normalizeAssignTaskType($taskType);
        $normalizedRewriteMode = SeoProjectTask::normalizeRewriteMode($rewriteMode);
        $normalizedRewriteNotes = $normalizedRewriteMode === SeoProjectTask::REWRITE_MODE_CONTENT
            ? (trim((string) ($rewriteNotes ?? '')) !== '' ? trim((string) $rewriteNotes) : null)
            : null;

        DB::connection($project->getConnectionName())->transaction(function () use (
            $project,
            $records,
            $projectSiteId,
            $targetProjectId,
            $normalizedTaskType,
            $normalizedRewriteMode,
            $normalizedRewriteNotes,
            $dryRun,
            &$added,
            &$duplicate,
            &$overflow,
            &$domainMismatch,
            &$alreadyInProject,
        ): void {
            $project->refresh();
            $max = $project->maxTasksAllowed();
            $currentTotal = $project->registeredTaskCount();

            $existingKeys = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->get(['site_id', 'type', 'source_content'])
                ->map(static fn (SeoProjectTask $task): string => (int) $task->site_id.'|'.(string) $task->type.'|'.mb_strtolower(trim((string) $task->source_content)))
                ->all();
            $existingMap = array_fill_keys($existingKeys, true);

            foreach ($records as $record) {
                if ($currentTotal >= $max) {
                    $overflow++;

                    continue;
                }

                if ($this->articleIsContentArchived($record)) {
                    $alreadyInProject++;

                    continue;
                }

                $assignedProjectId = $this->articleAssignedContentProjectId($record);
                if ($assignedProjectId !== null) {
                    if ($assignedProjectId === $targetProjectId) {
                        $duplicate++;
                    } else {
                        $alreadyInProject++;
                    }

                    continue;
                }

                $articleSiteId = $this->resolveArticleSiteId($record) ?? 0;
                if ($projectSiteId > 0 && $articleSiteId !== $projectSiteId) {
                    $domainMismatch++;

                    continue;
                }

                $sourceContent = $this->resolveAssignSourceContent($record, $normalizedTaskType);
                $siteId = $projectSiteId > 0 ? $projectSiteId : $articleSiteId;
                $key = $siteId.'|'.$normalizedTaskType.'|'.mb_strtolower($sourceContent);
                if (isset($existingMap[$key])) {
                    $duplicate++;

                    continue;
                }

                if (! $dryRun) {
                    $payload = [
                        'project_id' => (int) $project->id,
                        'site_id' => $siteId > 0 ? $siteId : null,
                        'type' => $normalizedTaskType,
                        'source_content' => $sourceContent,
                        'description' => null,
                        'target_date' => $project->monthCarbon()->copy()->addDays($currentTotal)->format('Y-m-d'),
                        'status' => SeoProjectTask::STATUS_PENDING,
                    ];

                    if (SeoProjectTask::isNewArticleType($normalizedTaskType)) {
                        $payload['post_type'] = SeoProjectTask::POST_TYPE_ARTICLE;
                        $payload['article_id'] = null;
                    } else {
                        $payload['article_id'] = (int) $record->id;
                    }

                    if ($normalizedTaskType === SeoProjectTask::TYPE_REWRITE) {
                        $payload['rewrite_mode'] = $normalizedRewriteMode;
                        $payload['rewrite_notes'] = $normalizedRewriteNotes;
                    }

                    $payload['source_key'] = app(\App\Addons\SeoContentAi\Support\ProjectTaskSourceKeyGenerator::class)->generate(
                        (int) $project->id,
                        $normalizedTaskType,
                        isset($payload['post_type']) ? (string) $payload['post_type'] : null,
                        $sourceContent,
                    );

                    SeoProjectTask::query()->create($payload);
                }

                $existingMap[$key] = true;
                $currentTotal++;
                $added++;
            }

            if (! $dryRun) {
                $project->syncTotalTasksCounter();
            }
        });

        if (! $dryRun && $added > 0) {
            $this->articleOwnerSync->syncProjectArticles($project->fresh() ?? $project);
        }

        return [
            'added' => $added,
            'duplicate' => $duplicate,
            'overflow' => $overflow,
            'domain_mismatch' => $domainMismatch,
            'already_in_project' => $alreadyInProject,
        ];
    }

    /**
     * @param  array{added?:int, duplicate?:int, overflow?:int, domain_mismatch?:int, already_in_project?:int}  $summary
     */
    public function buildSummaryMessage(array $summary): string
    {
        return __('seo-content-ai::filament.article_list.assign_completed_body', [
            'added' => (int) ($summary['added'] ?? 0),
            'duplicate' => (int) ($summary['duplicate'] ?? 0),
            'overflow' => (int) ($summary['overflow'] ?? 0),
            'domain_mismatch' => (int) ($summary['domain_mismatch'] ?? 0),
            'already_in_project' => (int) ($summary['already_in_project'] ?? 0),
        ]);
    }

    public function normalizeAssignTaskType(mixed $value): string
    {
        $type = trim((string) $value);

        return in_array($type, [
            SeoProjectTask::TYPE_REWRITE,
            SeoProjectTask::TYPE_NEW_KEYWORD,
            SeoProjectTask::TYPE_NEW_TITLE,
            SeoProjectTask::TYPE_IMPROVE,
        ], true)
            ? $type
            : SeoProjectTask::TYPE_REWRITE;
    }

    public function articleIsContentArchived(SeoArticle $article): bool
    {
        return $article->content_archived_at !== null;
    }

    public function resolveArticleSiteId(SeoArticle $article): ?int
    {
        $siteId = (int) ($article->site_id ?? 0);

        if ($siteId > 0) {
            return $siteId;
        }

        return SeoAccessControl::globalSiteId();
    }

    public function resolveArticleProjectSourceContent(SeoArticle $article): string
    {
        $sourceContent = trim((string) ($article->title ?? ''));
        if ($sourceContent === '') {
            return 'Article #'.(int) $article->id;
        }

        return $sourceContent;
    }

    public function resolveAssignSourceContent(SeoArticle $article, string $taskType): string
    {
        if ($this->normalizeAssignTaskType($taskType) === SeoProjectTask::TYPE_NEW_KEYWORD) {
            $keyword = trim((string) ($this->analyzer->resolveFocusKeywordForArticle($article) ?? ''));
            if ($keyword !== '') {
                return $keyword;
            }
        }

        return $this->resolveArticleProjectSourceContent($article);
    }

    public function articleAssignedContentProjectId(SeoArticle $article): ?int
    {
        $directProjectId = SeoProjectTask::query()
            ->where('article_id', (int) $article->id)
            ->value('project_id');
        if ($directProjectId !== null) {
            return (int) $directProjectId;
        }

        $needle = mb_strtolower(trim($this->resolveArticleProjectSourceContent($article)));
        $articleSiteId = $this->resolveArticleSiteId($article) ?? 0;

        $query = SeoProjectTask::query()
            ->whereIn('type', [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE])
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle]);

        if ($articleSiteId > 0) {
            $query->where(function (Builder $builder) use ($articleSiteId): void {
                $builder
                    ->where('site_id', $articleSiteId)
                    ->orWhereNull('site_id');
            });
        }

        $projectId = $query->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }
}
