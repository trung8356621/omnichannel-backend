<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Migration;

use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Runtime\ActionRunner;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\KeywordProjectAssignmentService;
use App\Addons\SeoContentAi\Services\SeoIssueProjectTaskAssignmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Group 1 callers: SEO issue assign + keyword assign — ActionRunner only (full cutover).
 */
final class AssignmentCallerBridge
{
    public function __construct(
        private readonly AutomationCallerMigrator $migrator,
        private readonly ActionRunner $actionRunner,
        private readonly SeoIssueProjectTaskAssignmentService $seoIssueAssignment,
        private readonly KeywordProjectAssignmentService $keywordAssignment,
        private readonly ParitySnapshotNormalizer $parityNormalizer,
    ) {}

    /**
     * @param  Collection<int, mixed>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public function assignArticlesToContentProject(
        Collection $records,
        int $projectId,
        string $taskType,
        ?string $rewriteMode,
        ?string $rewriteNotes,
        ?int $actorId = null,
    ): array {
        $articles = $records->filter(static fn (mixed $r): bool => $r instanceof SeoArticle)->values();
        $correlationId = Str::uuid()->toString();
        $normalizer = $this->parityNormalizer;

        $result = $this->migrator->run(
            callerKey: AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT,
            legacyWrite: fn (): array => $this->seoIssueAssignment->assignArticles(
                $articles,
                $projectId,
                $taskType,
                $rewriteMode,
                $rewriteNotes,
            ),
            actionWrite: function () use ($articles, $projectId, $taskType, $rewriteMode, $rewriteNotes, $actorId): ActionResult {
                $aggregate = [
                    'added' => 0,
                    'duplicate' => 0,
                    'overflow' => 0,
                    'domain_mismatch' => 0,
                    'already_in_project' => 0,
                ];
                foreach ($articles as $article) {
                    $one = $this->actionRunner->run(
                        'seo.project_task.create_from_issue',
                        ActionContext::fromArray([
                            'origin' => 'migration.seo_issue_assignment',
                            'actor_id' => $actorId,
                            'site_id' => (int) ($article->site_id ?? 0),
                            'correlation_id' => Str::uuid()->toString(),
                        ]),
                        [
                            'project_id' => $projectId,
                            'article_id' => (int) $article->id,
                            'type' => $taskType,
                            'rewrite_mode' => $rewriteMode,
                            'rewrite_notes' => $rewriteNotes,
                        ],
                    );
                    if (! $one->success) {
                        return $one;
                    }
                    $part = is_array($one->output['summary'] ?? null) ? $one->output['summary'] : [];
                    foreach (array_keys($aggregate) as $key) {
                        $aggregate[$key] += (int) ($part[$key] ?? 0);
                    }
                }

                return ActionResult::success(output: [
                    'summary' => $aggregate,
                    'added' => $aggregate['added'],
                    'duplicate' => $aggregate['duplicate'],
                ]);
            },
            parityExpected: fn (): array => $this->seoIssueAssignment->assignArticles(
                $articles,
                $projectId,
                $taskType,
                $rewriteMode,
                $rewriteNotes,
                dryRun: true,
            ),
            normalizeLegacy: static fn (mixed $v): array => $normalizer->assignment($v, $projectId),
            normalizeExpected: static fn (array $v): array => $normalizer->assignment($v, $projectId),
            actionKey: 'seo.project_task.create_from_issue',
            correlationId: $correlationId,
        );

        $summary = $this->parityNormalizer->assignment($result, $projectId)['resulting_state'];

        return $summary;
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public function assignKeywordsToContentProject(
        Collection $records,
        int $projectId,
        int $targetSiteId,
        ?int $actorId = null,
    ): array {
        $keywords = $records->filter(static fn (mixed $r): bool => $r instanceof Keyword)->values();
        $correlationId = Str::uuid()->toString();
        $normalizer = $this->parityNormalizer;

        $result = $this->migrator->run(
            callerKey: AutomationMigrationFlags::KEYWORD_PROJECT_ASSIGNMENT,
            legacyWrite: fn (): array => $this->keywordAssignment->assignKeywords(
                $keywords,
                $projectId,
                $targetSiteId,
            ),
            actionWrite: function () use ($keywords, $projectId, $targetSiteId, $actorId): ActionResult {
                $aggregate = [
                    'added' => 0,
                    'duplicate' => 0,
                    'overflow' => 0,
                    'domain_mismatch' => 0,
                    'already_in_project' => 0,
                ];
                foreach ($keywords as $keyword) {
                    $keyword->loadCount('mainArticles');
                    $one = $this->actionRunner->run(
                        'keyword.assign_to_project',
                        ActionContext::fromArray([
                            'origin' => 'migration.keyword_project_assignment',
                            'actor_id' => $actorId,
                            'site_id' => $targetSiteId,
                        ]),
                        [
                            'project_id' => $projectId,
                            'keyword_id' => (int) $keyword->id,
                            'site_id' => $targetSiteId,
                        ],
                    );
                    if (! $one->success) {
                        return $one;
                    }
                    $part = is_array($one->output['summary'] ?? null) ? $one->output['summary'] : [];
                    foreach (array_keys($aggregate) as $key) {
                        $aggregate[$key] += (int) ($part[$key] ?? 0);
                    }
                }

                return ActionResult::success(output: [
                    'summary' => $aggregate,
                    'added' => $aggregate['added'],
                    'duplicate' => $aggregate['duplicate'],
                ]);
            },
            parityExpected: fn (): array => $this->keywordAssignment->assignKeywords(
                $keywords,
                $projectId,
                $targetSiteId,
                dryRun: true,
            ),
            normalizeLegacy: static fn (mixed $v): array => $normalizer->assignment($v, $projectId),
            normalizeExpected: static fn (array $v): array => $normalizer->assignment($v, $projectId),
            actionKey: 'keyword.assign_to_project',
            correlationId: $correlationId,
        );

        return $this->parityNormalizer->assignment($result, $projectId)['resulting_state'];
    }
}
