<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;

final class ArticleEditorSeoPayloadService
{
    public function __construct(
        private readonly ArticleContentSeoBonusService $contentBonus,
    ) {}

    /**
     * Payload SEO cho editor / popup (tab Điểm SEO).
     *
     * @return array<string, mixed>
     */
    public function forArticle(SeoArticle $article): array
    {
        $article->loadMissing(['articleMetas', 'keywords', 'site', 'links', 'faqs']);

        $analysis = $this->decodeArticleMetaJson($article, 'seo_rank_math_score');
        $extractedLinks = $article->resolveExtractedLinks();
        $bodyHtml = (string) ($article->body ?? '');
        $suggestedInternalLinks = app(ArticleInternalLinkSuggestionService::class)->suggest(
            $article,
            $bodyHtml,
            $extractedLinks['internal'] ?? [],
        );
        $contentBonus = $this->contentBonus->resolveForArticle($article);

        if (! is_array($analysis) && $article->seo_score !== null) {
            $analysis = [
                'score' => (int) round((float) $article->seo_score),
                'good' => [],
                'errors' => [],
                'warnings' => [],
            ];
        }

        if (is_array($analysis)) {
            $analysis['content_bonus'] = $contentBonus;
        }

        return [
            'focus_keyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article),
            'article_type' => (string) ($article->type ?? 'post'),
            'score' => $article->seo_score !== null ? (int) round((float) $article->seo_score) : null,
            'analysis' => is_array($analysis) ? $analysis : null,
            'content_bonus' => $contentBonus,
            'extracted_links' => $extractedLinks,
            'suggested_internal_links' => $suggestedInternalLinks,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeArticleMetaJson(SeoArticle $article, string $key): ?array
    {
        /** @var ArticleMeta|null $meta */
        $meta = $article->articleMetas->firstWhere('meta_key', $key);
        if ($meta === null || ! is_string($meta->meta_value) || trim($meta->meta_value) === '') {
            return null;
        }

        $decoded = json_decode($meta->meta_value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
