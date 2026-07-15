<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Enums\KeywordReviewStatus;
use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\SeoRuleViolationsResolver;
use App\Addons\SeoContentAi\Support\SeoScoringRulesRegistry;
use App\Addons\SeoContentAi\Support\SeoScoringStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

final class SeoAuditKeywordFlagService
{
    public function __construct(
        private readonly SeoArticleQualityAssessmentService $assessmentService,
        private readonly SeoAuditScanService $auditScanService,
    ) {}

    /**
     * @param  Builder<SeoArticle>  $baseQuery
     * @return Builder<SeoArticle>
     */
    public function applyKeywordFlagScope(Builder $baseQuery): Builder
    {
        return (clone $baseQuery)->whereHas('linkMaps.keyword', static function (Builder $keywordQuery): void {
            $keywordQuery->whereIn('review_status', [
                KeywordReviewStatus::Warning->value,
                KeywordReviewStatus::Danger->value,
            ]);
        });
    }

    /**
     * @param  Builder<SeoArticle>  $baseQuery
     * @param  list<string>  $selectedRuleKeys
     */
    public function paginateMergedResults(
        Builder $baseQuery,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
        int $page = 1,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $keywordArticleIds = $this->applyKeywordFlagScope($baseQuery)
            ->pluck('articles.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $hasScoringSelection = $selectedRuleKeys !== []
            || $filterLowSeoScore
            || $filterTechnicalSeoScore;

        $ruleArticleIds = [];
        if ($hasScoringSelection) {
            $ruleArticleIds = $this->auditScanService
                ->buildFilteredQuery($baseQuery, $selectedRuleKeys, $filterLowSeoScore, $filterTechnicalSeoScore)
                ->pluck('articles.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        $mergedIds = array_values(array_unique(array_merge($keywordArticleIds, $ruleArticleIds)));
        if ($mergedIds === []) {
            return new Paginator([], 0, $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);
        }

        $articles = ArticleResource::applySeoAuditCandidateScope(
            SeoArticle::query()->whereIn('id', $mergedIds)
        )
            ->with([
                'site:id,domain',
                'articleMetas' => static function ($relation): void {
                    $relation->whereIn('meta_key', [
                        SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
                        'seo_focus_keyword',
                        'wp_permalink',
                        'meta_description',
                        'seo_meta_description',
                        '_yoast_wpseo_metadesc',
                        'rank_math_description',
                    ]);
                },
                'linkMaps.keyword.reviewReason',
            ])
            ->get()
            ->keyBy('id');

        $rows = collect($mergedIds)
            ->map(function (int $articleId) use ($articles, $selectedRuleKeys, $filterLowSeoScore, $filterTechnicalSeoScore): ?array {
                $article = $articles->get($articleId);
                if (! $article instanceof SeoArticle) {
                    return null;
                }

                return $this->mapMergedArticleRow(
                    $article,
                    $selectedRuleKeys,
                    $filterLowSeoScore,
                    $filterTechnicalSeoScore,
                );
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                $dangerCompare = ((int) ($right['danger_count'] ?? 0)) <=> ((int) ($left['danger_count'] ?? 0));
                if ($dangerCompare !== 0) {
                    return $dangerCompare;
                }

                $warningCompare = ((int) ($right['warning_count'] ?? 0)) <=> ((int) ($left['warning_count'] ?? 0));
                if ($warningCompare !== 0) {
                    return $warningCompare;
                }

                return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
            })
            ->values();

        $total = $rows->count();
        $offset = max(0, ($page - 1) * $perPage);
        $pageItems = $rows->slice($offset, $perPage)->values()->all();

        return new Paginator(
            $pageItems,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @param  list<string>  $selectedRuleKeys
     * @return array<string, mixed>
     */
    private function mapMergedArticleRow(
        SeoArticle $article,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
    ): array {
        $violations = SeoRuleViolationsResolver::forArticle($article);
        $assessment = $this->assessmentService->assessFromAnalysis([
            'violations' => $violations,
            'seo_score' => $article->seo_score !== null ? (int) round((float) $article->seo_score) : null,
        ]);

        $keywordFlags = $this->collectKeywordFlagsForArticle($article);
        $hasKeywordFlags = $keywordFlags['warning_count'] > 0 || $keywordFlags['danger_count'] > 0;
        $focusKeyword = trim((string) (
            $article->articleMetas
                ->firstWhere('meta_key', 'seo_focus_keyword')
                ?->meta_value ?? ''
        ));
        $hasFocusKeyword = $focusKeyword !== '';

        $hasScoringSelection = $selectedRuleKeys !== []
            || $filterLowSeoScore
            || $filterTechnicalSeoScore;

        $matchesRules = false;
        if ($hasScoringSelection) {
            $matchesRules = $this->articleMatchesScoringFilters(
                $article,
                $assessment['matched_rule_keys'] ?? [],
                $selectedRuleKeys,
                $filterLowSeoScore,
                $filterTechnicalSeoScore,
                (int) ($assessment['score'] ?? 0),
            );
        }

        $sources = [];
        if ($hasKeywordFlags) {
            $sources[] = 'keyword_review';
        }
        if ($matchesRules) {
            $sources[] = 'seo_rules';
        }

        return [
            'id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0),
            'title' => (string) ($article->title ?? ''),
            'domain' => (string) ($article->site?->domain ?? ''),
            'permalink' => $this->resolveCachedPermalink($article),
            'edit_url' => ArticleResource::getUrl('edit', ['record' => $article]),
            'score' => $assessment['score'],
            'technical_score' => $assessment['technical_score'],
            'matched_rule_keys' => $assessment['matched_rule_keys'],
            'violations' => $assessment['matched_rule_keys'],
            'reason_keys' => $assessment['matched_rule_keys'],
            'reason_labels' => array_map(
                static fn (array $item): string => (string) ($item['label'] ?? ''),
                $assessment['active_violations'],
            ),
            'is_low_quality' => $assessment['is_low_quality'],
            'is_analyzed' => SeoScoringStatus::hasBeenAnalyzed($article),
            'warning_count' => $keywordFlags['warning_count'],
            'danger_count' => $keywordFlags['danger_count'],
            'flagged_keywords' => $keywordFlags['items'],
            'focus_keyword' => $focusKeyword,
            'has_focus_keyword' => $hasFocusKeyword,
            'audit_sources' => $sources,
            'has_keyword_flags' => $hasKeywordFlags,
            'has_seo_rule_matches' => $matchesRules,
            'updated_at' => optional($article->updated_at)->toIso8601String() ?? '',
        ];
    }

    /**
     * @return array{
     *   warning_count: int,
     *   danger_count: int,
     *   items: list<array{
     *     id: int,
     *     phrase: string,
     *     review_status: string,
     *     reason: string|null,
     *     note: string|null
     *   }>
     * }
     */
    private function collectKeywordFlagsForArticle(SeoArticle $article): array
    {
        $keywords = $article->linkMaps
            ->pluck('keyword')
            ->filter()
            ->unique('id')
            ->filter(static function (?Keyword $keyword): bool {
                if (! $keyword instanceof Keyword) {
                    return false;
                }

                $status = KeywordReviewStatus::tryFrom((string) $keyword->review_status);

                return $status?->isNegative() === true;
            });

        $items = $keywords->map(static function (Keyword $keyword): array {
            return [
                'id' => (int) $keyword->id,
                'phrase' => (string) $keyword->phrase,
                'review_status' => (string) $keyword->review_status,
                'reason' => $keyword->reviewReason?->name,
                'note' => $keyword->review_note,
            ];
        })->values()->all();

        return [
            'warning_count' => $keywords->where('review_status', KeywordReviewStatus::Warning->value)->count(),
            'danger_count' => $keywords->where('review_status', KeywordReviewStatus::Danger->value)->count(),
            'items' => $items,
        ];
    }

    /**
     * @param  list<string>  $matchedRuleKeys
     * @param  list<string>  $selectedRuleKeys
     */
    private function articleMatchesScoringFilters(
        SeoArticle $article,
        array $matchedRuleKeys,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
        int $score,
    ): bool {
        $selectedRuleKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $selectedRuleKeys,
        ), static fn (string $key): bool => $key !== '')));

        foreach ($selectedRuleKeys as $ruleKey) {
            if ($ruleKey === SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD) {
                if ($this->auditScanService->isMissingFocusKeywordOnly([$ruleKey], false, false)) {
                    $missing = ! $article->articleMetas
                        ->where('meta_key', 'seo_focus_keyword')
                        ->filter(static fn ($meta): bool => trim((string) ($meta->meta_value ?? '')) !== '')
                        ->isNotEmpty();

                    if ($missing) {
                        return true;
                    }
                }
            }

            if (in_array($ruleKey, $matchedRuleKeys, true)) {
                return true;
            }
        }

        $threshold = SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD;
        if (($filterLowSeoScore || $filterTechnicalSeoScore) && $score < $threshold) {
            return true;
        }

        return false;
    }

    private function resolveCachedPermalink(SeoArticle $article): ?string
    {
        $meta = $article->articleMetas->firstWhere('meta_key', 'wp_permalink');
        $permalink = trim((string) ($meta?->meta_value ?? ''));

        return $permalink !== '' ? $permalink : null;
    }
}
