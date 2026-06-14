<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoLink;
use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use App\Models\Site;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class DomainOverviewService
{
    /**
     * @return array{
     *     read_token_masked: string,
     *     migration_token_masked: string,
     *     has_read_token: bool,
     *     has_migration_token: bool,
     *     platform: string,
     *     seo_plugin: string,
     *     seo_plugin_fetched_at: string,
     * }
     */
    public function getApiTokenSummary(Site $site): array
    {
        $site->loadMissing('metas');
        $platform = (string) ($site->getMeta('seo_platform') ?? 'wordpress');
        $read = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        $migration = trim((string) ($site->getMeta('seo_migration_token') ?? ''));

        return [
            'read_token_masked' => $this->maskToken($read),
            'migration_token_masked' => $this->maskToken($migration),
            'has_read_token' => $read !== '',
            'has_migration_token' => $migration !== '',
            'platform' => $platform,
            'seo_plugin' => trim((string) ($site->getMeta(WordPressSiteInfoService::META_PLUGIN) ?? '')),
            'seo_plugin_fetched_at' => trim((string) ($site->getMeta(WordPressSiteInfoService::META_PLUGIN_FETCHED_AT) ?? '')),
        ];
    }

    /**
     * @return array{read_token: string, migration_token: string}
     */
    public function getApiTokensPlain(Site $site): array
    {
        $site->loadMissing('metas');

        return [
            'read_token' => trim((string) ($site->getMeta('seo_read_token') ?? '')),
            'migration_token' => trim((string) ($site->getMeta('seo_migration_token') ?? '')),
        ];
    }

    /**
     * Phân bố điểm SEO theo nhóm (cho biểu đồ tròn).
     *
     * @return array{
     *     total: int,
     *     scored: int,
     *     segments: list<array{label: string, key: string, count: int, color: string}>,
     * }
     */
    public function getScoreDistribution(int $siteId): array
    {
        $base = SeoArticle::query()->where('site_id', $siteId)->countsTowardSeoScore();
        $total = (clone $base)->count();
        $scored = (clone $base)->whereNotNull('seo_score')->count();

        if ($scored === 0) {
            return [
                'total' => $total,
                'scored' => 0,
                'segments' => [],
            ];
        }

        $row = (clone $base)
            ->whereNotNull('seo_score')
            ->selectRaw('
                SUM(CASE WHEN seo_score < 50 THEN 1 ELSE 0 END) as poor,
                SUM(CASE WHEN seo_score >= 50 AND seo_score < 70 THEN 1 ELSE 0 END) as fair,
                SUM(CASE WHEN seo_score >= 70 AND seo_score < 90 THEN 1 ELSE 0 END) as good,
                SUM(CASE WHEN seo_score >= 90 THEN 1 ELSE 0 END) as excellent
            ')
            ->first();

        $segments = [
            ['label' => '0–49', 'key' => 'poor', 'count' => (int) ($row->poor ?? 0), 'color' => '#ef4444'],
            ['label' => '50–69', 'key' => 'fair', 'count' => (int) ($row->fair ?? 0), 'color' => '#f59e0b'],
            ['label' => '70–89', 'key' => 'good', 'count' => (int) ($row->good ?? 0), 'color' => '#3b82f6'],
            ['label' => '90–100', 'key' => 'excellent', 'count' => (int) ($row->excellent ?? 0), 'color' => '#22c55e'],
        ];

        return [
            'total' => $total,
            'scored' => $scored,
            'segments' => $segments,
        ];
    }

    public function buildArticlesFilterUrl(int $siteId, string $band): string
    {
        return $this->appendArticlesTableFilters(ArticleResource::panelUrl('index'), [
            'site_id' => ['value' => (string) $siteId],
            'seo_score_band' => ['value' => $band],
        ]);
    }

    public function buildArticlesFilterUrlForLink(int $siteId, string $url, string $type): string
    {
        return $this->appendArticlesTableFilters(ArticleResource::panelUrl('index'), [
            'site_id' => ['value' => (string) $siteId],
            'seo_link' => [
                'url' => $url,
                'type' => $type,
            ],
        ]);
    }

    /**
     * Bài viết gắn từ khóa và có ít nhất một link nội bộ trong nội dung.
     */
    public function buildArticlesFilterUrlForKeyword(int $siteId, int $keywordId): string
    {
        return $this->buildArticlesFilterUrlForInternalAnchorKeyword($siteId, $keywordId);
    }

    public function buildArticlesFilterUrlForMainKeyword(int $siteId, int $keywordId): string
    {
        return $this->appendArticlesTableFilters(ArticleResource::panelUrl('index'), [
            'site_id' => ['value' => (string) $siteId],
            'keyword' => [
                'keyword_id' => (string) $keywordId,
                'usage' => 'main',
            ],
        ]);
    }

    public function buildArticlesFilterUrlForInternalAnchorKeyword(int $siteId, int $keywordId): string
    {
        return $this->appendArticlesTableFilters(ArticleResource::panelUrl('index'), [
            'site_id' => ['value' => (string) $siteId],
            'keyword' => [
                'keyword_id' => (string) $keywordId,
                'usage' => 'internal_link',
            ],
        ]);
    }

    /**
     * @param  array<string, array<string, string>>  $tableFilters
     */
    private function appendArticlesTableFilters(string $base, array $tableFilters): string
    {
        $query = http_build_query(['tableFilters' => $tableFilters]);

        return $base.(str_contains($base, '?') ? '&' : '?').$query;
    }

    /**
     * @return array{scored: int, avg_score: float|null, min_score: float|null, max_score: float|null}
     */
    public function getScoringStatistics(int $siteId): array
    {
        $base = SeoArticle::query()
            ->where('site_id', $siteId)
            ->countsTowardSeoScore()
            ->whereNotNull('seo_score');
        $scored = (clone $base)->count();

        if ($scored === 0) {
            return [
                'scored' => 0,
                'avg_score' => null,
                'min_score' => null,
                'max_score' => null,
            ];
        }

        return [
            'scored' => $scored,
            'avg_score' => round((float) (clone $base)->avg('seo_score'), 1),
            'min_score' => round((float) (clone $base)->min('seo_score'), 1),
            'max_score' => round((float) (clone $base)->max('seo_score'), 1),
        ];
    }

    /**
     * @return array{articles: int, products: int, categories: int, product_categories: int, other: int, total: int}
     */
    public function getSyncStatistics(int $siteId): array
    {
        $base = SeoArticle::query()->where('site_id', $siteId);

        $articles = (clone $base)->where(function ($q): void {
            $q->where('type', 'article')->orWhereNull('type');
        })->count();

        $products = (clone $base)->where('type', 'product')->count();
        $categories = (clone $base)->where('type', 'category')->count();
        $productCategories = (clone $base)->where('type', 'product_category')->count();

        $other = (clone $base)->whereNotNull('type')
            ->whereNotIn('type', ['article', 'product', 'category', 'product_category'])
            ->count();

        return [
            'articles' => $articles,
            'products' => $products,
            'categories' => $categories,
            'product_categories' => $productCategories,
            'other' => $other,
            'total' => $articles + $products + $categories + $productCategories + $other,
        ];
    }

    /**
     * @return Collection<int, object{id: int, phrase: string, articles_count: int}>
     */
    public function getTopKeywords(int $siteId, int $limit = 8): Collection
    {
        return Keyword::query()
            ->whereHas('articles', static fn ($query) => $query->where('articles.site_id', $siteId))
            ->join('article_keyword', 'article_keyword.keyword_id', '=', 'keywords.id')
            ->join('articles', function ($join) use ($siteId): void {
                $join->on('articles.id', '=', 'article_keyword.article_id')
                    ->where('articles.site_id', '=', $siteId)
                    ->whereNull('articles.deleted_at');
            })
            ->select('keywords.id', 'keywords.phrase')
            ->selectRaw('COUNT(DISTINCT articles.id) as articles_count')
            ->groupBy('keywords.id', 'keywords.phrase')
            ->orderByDesc('articles_count')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, object{url: string, type: string, articles_count: int}>
     */
    public function getTopLinks(int $siteId, int $limit = 8): Collection
    {
        return $this->linksGroupedQuery($siteId)
            ->limit($limit)
            ->get();
    }

    public function paginateLinks(int $siteId, int $perPage = 25): LengthAwarePaginator
    {
        return $this->linksGroupedQuery($siteId)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateKeywords(int $siteId, int $perPage = 25): LengthAwarePaginator
    {
        $query = Keyword::query()
            ->forSite($siteId);

        InternalAnchorKeywordFilter::applyExcludeLinkLikePhrases($query);

        return $query
            ->withCount([
                'mainArticles as main_articles_count',
                'links as linked_articles_count' => static fn ($linkQuery) => $linkQuery
                    ->where('seo_links.site_id', $siteId)
                    ->whereNotNull('seo_links.source_article_id'),
            ])
            ->orderByDesc('linked_articles_count')
            ->orderByDesc('main_articles_count')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SeoLink>
     */
    private function linksGroupedQuery(int $siteId)
    {
        return SeoLink::query()
            ->join('articles', function ($join) use ($siteId): void {
                $join->on('articles.id', '=', 'seo_links.source_article_id')
                    ->where('articles.site_id', '=', $siteId)
                    ->whereNull('articles.deleted_at');
            })
            ->where('seo_links.site_id', $siteId)
            ->selectRaw('MIN(seo_links.id) as id')
            ->addSelect('seo_links.url', 'seo_links.type')
            ->selectRaw('COUNT(DISTINCT seo_links.source_article_id) as articles_count')
            ->groupBy('seo_links.url', 'seo_links.type')
            ->orderByDesc('articles_count');
    }

    /**
     * @return array{
     *     short_description_preview: string,
     *     cta_count: int,
     *     links_count: int,
     *     has_content: bool,
     * }
     */
    public function getTechnicalSeoSummary(Site $site): array
    {
        $ctx = app(SiteDomainPromptContextService::class)->getForSite($site);
        $desc = trim((string) ($ctx['short_description'] ?? ''));
        $preview = $desc === '' ? '' : mb_substr($desc, 0, 160).(mb_strlen($desc) > 160 ? '…' : '');

        return [
            'short_description_preview' => $preview,
            'cta_count' => count($ctx['cta'] ?? []),
            'links_count' => count($ctx['links'] ?? []),
            'has_content' => $desc !== '' || count($ctx['cta'] ?? []) > 0 || count($ctx['links'] ?? []) > 0,
        ];
    }

    public function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '—';
        }

        $len = mb_strlen($token);
        if ($len <= 3) {
            return str_repeat('•', max(0, $len - 3)).$token;
        }

        return str_repeat('•', min(24, $len - 3)).mb_substr($token, -3);
    }

    public function isSiteSynced(int $siteId): bool
    {
        return SeoArticle::query()->where('site_id', $siteId)->exists();
    }
}
