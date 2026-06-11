<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticleHeading;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dò trùng heading trong phạm vi 1 site:
 * - Exact match qua index `heading_slug`.
 * - Semantic match qua MySQL FULLTEXT (Natural Language Mode).
 */
class HeadingDuplicateCheckService
{
    private const DEFAULT_LIMIT = 10;

    /**
     * Tìm heading trùng slug tuyệt đối trong site.
     *
     * @return Collection<int, SeoArticleHeading>
     */
    public function checkExactMatch(string $slug, int $siteId, ?int $excludeArticleId = null, ?int $level = null): Collection
    {
        $slug = trim($slug);
        if ($slug === '') {
            return new Collection;
        }

        $query = $this->siteScopedQuery($siteId, $excludeArticleId)
            ->where('heading_slug', $slug);

        if ($level !== null && $level > 0) {
            $query->where('level', $level);
        }

        return $query->limit(self::DEFAULT_LIMIT)->get();
    }

    /**
     * Tìm heading trùng ngữ nghĩa, trả về kèm `score` (relevance) giảm dần.
     *
     * @return Collection<int, SeoArticleHeading> mỗi record có thêm thuộc tính ảo `score`
     */
    public function checkSemanticMatch(string $text, int $siteId, ?int $excludeArticleId = null, ?int $level = null): Collection
    {
        $text = trim($text);
        if ($text === '') {
            return new Collection;
        }

        $query = $this->siteScopedQuery($siteId, $excludeArticleId)
            ->select('seo_article_headings.*')
            ->selectRaw('MATCH(heading_text) AGAINST(? IN NATURAL LANGUAGE MODE) AS score', [$text])
            ->whereRaw('MATCH(heading_text) AGAINST(? IN NATURAL LANGUAGE MODE)', [$text]);

        if ($level !== null && $level > 0) {
            $query->where('level', $level);
        }

        return $query
            ->orderByDesc('score')
            ->limit(self::DEFAULT_LIMIT)
            ->get();
    }

    /**
     * @return Builder<SeoArticleHeading>
     */
    private function siteScopedQuery(int $siteId, ?int $excludeArticleId = null): Builder
    {
        $query = SeoArticleHeading::query()
            ->with('article:id,title,slug,site_id')
            ->whereHas('article', function (Builder $sub) use ($siteId): void {
                $sub->where('site_id', $siteId);
            });

        if ($excludeArticleId !== null && $excludeArticleId > 0) {
            $query->where('article_id', '!=', $excludeArticleId);
        }

        return $query;
    }
}
