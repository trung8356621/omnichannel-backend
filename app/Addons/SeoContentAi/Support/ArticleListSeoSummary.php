<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleContentSeoBonusService;
use App\Addons\SeoContentAi\Services\ArticlePostImagesService;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;

final class ArticleListSeoSummary
{
    /**
     * @return array{
     *     score: ?int,
     *     score_skipped: bool,
     *     score_tone: string,
     *     keyword: ?string,
     *     schema: string,
     *     links_total: int,
     *     links_internal: int,
     *     links_external: int,
     *     image_count: int,
     *     faq_count: int,
     *     featured_snippet_points: int,
     *     faq_points: int,
     *     edit_url: string,
     * }
     */
    public static function for(SeoArticle $article): array
    {
        $article->loadMissing(['articleMetas', 'faqs']);

        $keyword = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article);

        $internal = $article->internal_link_count;
        $external = $article->external_link_count;

        $skipped = ! $article->countsTowardSeoScore();

        $score = ! $skipped && $article->seo_score !== null && $article->seo_score !== ''
            ? (int) round((float) $article->seo_score)
            : null;

        $contentBonus = app(ArticleContentSeoBonusService::class)->resolveForArticle($article);

        return [
            'score' => $score,
            'score_skipped' => $skipped,
            'score_tone' => $skipped ? 'skipped' : self::scoreTone($score),
            'keyword' => $keyword,
            'schema' => self::schemaLabel($article),
            'links_total' => $internal + $external,
            'links_internal' => $internal,
            'links_external' => $external,
            'image_count' => app(ArticlePostImagesService::class)->countForArticle($article),
            'faq_count' => $contentBonus['faq_count'],
            'featured_snippet_points' => $contentBonus['items']['featured_snippet']['points'],
            'faq_points' => $contentBonus['items']['faq']['points'],
            'edit_url' => ArticleResource::panelUrl('edit', ['record' => $article]),
        ];
    }

    public static function schemaLabel(SeoArticle $article): string
    {
        return match ((string) ($article->type ?? 'article')) {
            'product' => 'Sản phẩm (Product)',
            'page' => 'Trang (WebPage)',
            'category' => 'Danh mục (CollectionPage)',
            'product_category' => 'Danh mục sản phẩm (CollectionPage)',
            default => 'Bài viết (NewsArticle)',
        };
    }

    private static function scoreTone(?int $score): string
    {
        if ($score === null) {
            return 'muted';
        }

        return match (true) {
            $score < 50 => 'danger',
            $score < 70 => 'warning',
            default => 'success',
        };
    }
}
