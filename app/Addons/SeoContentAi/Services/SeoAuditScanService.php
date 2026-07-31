<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Enums\KeywordMetaKey;
use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\SeoRuleViolationsResolver;
use App\Addons\SeoContentAi\Support\SeoScoringRulesRegistry;
use App\Addons\SeoContentAi\Support\SeoScoringStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Schema;

final class SeoAuditScanService
{
    private const AUDIT_META_KEYS = [
        SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
        'wp_permalink',
        'meta_description',
        'seo_meta_description',
        '_yoast_wpseo_metadesc',
        'rank_math_description',
    ];

    public function __construct(
        private readonly SeoArticleQualityAssessmentService $assessmentService,
    ) {}

    /**
     * @param  Builder<SeoArticle>  $baseQuery
     * @param  list<string>  $selectedRuleKeys
     */
    public function paginateResults(
        Builder $baseQuery,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
        int $page = 1,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $selectedRuleKeys = $this->normalizeSelectedRuleKeys($selectedRuleKeys);
        $query = $this->buildFilteredQuery(
            $baseQuery,
            $selectedRuleKeys,
            $filterLowSeoScore,
            $filterTechnicalSeoScore,
        );

        $query->select([
            'articles.id',
            'articles.site_id',
            'articles.title',
            'articles.seo_score',
            'articles.slug',
            'articles.updated_at',
        ])->with([
            'site:id,domain',
            'articleMetas' => static function ($relation): void {
                $relation->whereIn('meta_key', self::AUDIT_META_KEYS);
            },
        ]);

        /** @var LengthAwarePaginator<int, SeoArticle> $paginator */
        $paginator = $query->paginate(
            perPage: $perPage,
            page: $page,
        );

        $rows = $paginator->getCollection()
            ->map(fn (SeoArticle $article): array => $this->mapArticleRow($article))
            ->values()
            ->all();

        return new Paginator(
            $rows,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @param  Builder<SeoArticle>  $baseQuery
     * @return array{
     *   total: int,
     *   analyzed: int,
     *   pending: int,
     *   processing: int,
     *   failed: int,
     *   remaining: int
     * }
     */
    public function cacheStatusCounts(Builder $baseQuery): array
    {
        $total = (clone $baseQuery)->count();

        $analyzed = (clone $baseQuery)->where(function (Builder $query): void {
            $query->whereHas('articleMetas', static function (Builder $meta): void {
                $meta->where('meta_key', SeoScoringRulesRegistry::META_KEY_VIOLATIONS);
            })->orWhereNotNull('seo_score');
        })->count();

        $pending = (clone $baseQuery)->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                ->where('meta_value', SeoScoringStatus::STATUS_PENDING);
        })->count();

        $processing = (clone $baseQuery)->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                ->where('meta_value', SeoScoringStatus::STATUS_PROCESSING);
        })->count();

        $failed = (clone $baseQuery)->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                ->where('meta_value', SeoScoringStatus::STATUS_FAILED);
        })->count();

        return [
            'total' => $total,
            'analyzed' => $analyzed,
            'pending' => $pending,
            'processing' => $processing,
            'failed' => $failed,
            'remaining' => max(0, $total - $analyzed - $pending - $processing),
        ];
    }

    /**
     * @param  list<string>  $selectedRuleKeys
     */
    public function isMissingFocusKeywordOnly(
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
    ): bool {
        $selectedRuleKeys = $this->normalizeSelectedRuleKeys($selectedRuleKeys);

        return $selectedRuleKeys === [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD]
            && ! $filterLowSeoScore
            && ! $filterTechnicalSeoScore;
    }

    /**
     * Canonical focus keyword: seo_focus_keyword meta (trim) HOẶC keyword_meta MainArticleId + phrase.
     * Warning/danger review status không đồng nghĩa thiếu keyword.
     */
    public function hasCanonicalFocusKeyword(SeoArticle $article): bool
    {
        $article->loadMissing('articleMetas');

        $metaValue = trim((string) (
            $article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''
        ));
        if ($metaValue !== '') {
            return true;
        }

        // Unit/SQLite :memory: có thể thiếu bảng — fail-closed = chưa có keyword canonical.
        if (
            ! Schema::connection('omi_seo_ai')->hasTable('keywords')
            || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')
        ) {
            return false;
        }

        try {
            return Keyword::query()
                ->whereHas(
                    'metas',
                    static function (Builder $meta) use ($article): void {
                        $meta->where('meta_key', KeywordMetaKey::MainArticleId->value)
                            ->where('meta_value', (string) $article->id);
                    },
                )
                ->whereNotNull('phrase')
                ->where('phrase', '!=', '')
                ->whereRaw("TRIM(phrase) <> ''")
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  Builder<SeoArticle>  $baseQuery
     * @param  list<string>  $selectedRuleKeys
     */
    public function buildFilteredQuery(
        Builder $baseQuery,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
    ): Builder {
        $selectedRuleKeys = $this->normalizeSelectedRuleKeys($selectedRuleKeys);
        $query = clone $baseQuery;

        $hasScoringSelection = $selectedRuleKeys !== [] || $filterLowSeoScore || $filterTechnicalSeoScore;
        $missingKeywordOnly = $this->isMissingFocusKeywordOnly($selectedRuleKeys, $filterLowSeoScore, $filterTechnicalSeoScore);

        // Fast path: chỉ thiếu keyword — không bắt buộc đã có cache violations/seo_score.
        if (! $hasScoringSelection || ! $missingKeywordOnly) {
            $query->where(function (Builder $analyzedScope): void {
                $analyzedScope->whereHas('articleMetas', static function (Builder $meta): void {
                    $meta->where('meta_key', SeoScoringRulesRegistry::META_KEY_VIOLATIONS);
                })->orWhereNotNull('seo_score');
            });
        }

        if (! $hasScoringSelection) {
            return $query;
        }

        $threshold = SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD;

        $enabledRuleKeys = array_values(array_filter(
            $selectedRuleKeys,
            static fn (string $ruleKey): bool => SeoScoringRulesRegistry::isRuleEnabled($ruleKey),
        ));

        if ($enabledRuleKeys === [] && ! $filterLowSeoScore && ! $filterTechnicalSeoScore) {
            return $query->whereRaw('0 = 1');
        }

        $query->where(function (Builder $orGroup) use ($enabledRuleKeys, $filterLowSeoScore, $filterTechnicalSeoScore, $threshold): void {
            foreach ($enabledRuleKeys as $ruleKey) {
                if ($ruleKey === SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD) {
                    $orGroup->orWhere(function (Builder $missingKeyword): void {
                        $this->applyMissingFocusKeywordScope($missingKeyword);
                    });

                    continue;
                }

                $orGroup->orWhereHas('articleMetas', static function (Builder $meta) use ($ruleKey): void {
                    $meta->where('meta_key', SeoScoringRulesRegistry::META_KEY_VIOLATIONS)
                        ->whereRaw(
                            '(JSON_VALID(meta_value) = 1 AND JSON_CONTAINS(meta_value, ?))',
                            [json_encode($ruleKey, JSON_THROW_ON_ERROR)]
                        );
                });
            }

            if ($filterLowSeoScore || $filterTechnicalSeoScore) {
                $orGroup->orWhere(function (Builder $lowScore) use ($threshold): void {
                    $lowScore->whereNotNull('seo_score')->where('seo_score', '<', $threshold);
                });
            }
        });

        return $query;
    }

    /**
     * @param  Builder<SeoArticle>  $query
     */
    public function applyMissingFocusKeywordScope(Builder $query): void
    {
        $query->whereNot(function (Builder $hasKeyword): void {
            $hasKeyword
                ->whereHas('articleMetas', static function (Builder $meta): void {
                    $meta->where('meta_key', 'seo_focus_keyword')
                        ->whereNotNull('meta_value')
                        ->where('meta_value', '!=', '')
                        ->whereRaw("TRIM(meta_value) <> ''");
                })
                ->orWhereExists(static function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('keyword_meta')
                        ->join('keywords', 'keywords.id', '=', 'keyword_meta.keyword_id')
                        ->whereColumn('keyword_meta.meta_value', 'articles.id')
                        ->where('keyword_meta.meta_key', KeywordMetaKey::MainArticleId->value)
                        ->whereNotNull('keywords.phrase')
                        ->where('keywords.phrase', '!=', '')
                        ->whereRaw("TRIM(keywords.phrase) <> ''");
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function mapArticleRow(SeoArticle $article): array
    {
        $violations = SeoRuleViolationsResolver::forArticle($article);
        $assessment = $this->assessmentService->assessFromAnalysis([
            'violations' => $violations,
            'seo_score' => $article->seo_score !== null ? (int) round((float) $article->seo_score) : null,
        ]);

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
        ];
    }

    private function resolveCachedPermalink(SeoArticle $article): ?string
    {
        $meta = $article->articleMetas->firstWhere('meta_key', 'wp_permalink');
        $permalink = trim((string) ($meta?->meta_value ?? ''));

        return $permalink !== '' ? $permalink : null;
    }

    /**
     * @param  list<string>  $selectedRuleKeys
     * @return list<string>
     */
    private function normalizeSelectedRuleKeys(array $selectedRuleKeys): array
    {
        return array_values(array_unique(array_filter(array_map(
            static function (mixed $key): ?string {
                if (! is_string($key) && ! is_numeric($key)) {
                    return null;
                }

                $normalized = trim((string) $key);

                return $normalized !== '' ? $normalized : null;
            },
            $selectedRuleKeys,
        ))));
    }
}
