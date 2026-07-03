<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Services\SeoEngineService;

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
        $article->loadMissing(['articleMetas', 'site', 'linkMaps', 'faqs']);

        $analysis = $this->decodeArticleMetaJson($article, 'seo_rank_math_score');
        $extractedLinks = $article->resolveExtractedLinks();
        $bodyHtml = (string) ($article->body ?? '');
        $internalLinks = $extractedLinks['internal'] ?? [];
        $suggestionService = app(ArticleInternalLinkSuggestionService::class);
        $suggestedInternalLinks = $suggestionService->suggest(
            $article,
            $bodyHtml,
            $internalLinks,
        );
        $suggestedInternalLinksCatalog = $suggestionService->suggestCatalog(
            $article,
            $bodyHtml,
            $internalLinks,
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

        $skipSeoScore = ! $article->countsTowardSeoScore();

        $seoTitle = trim((string) ($article->title ?? ''));

        $seoDescription = trim((string) (
            $article->articleMetas->first(
                static fn ($meta): bool => in_array((string) $meta->meta_key, [
                    'seo_meta_description',
                    'meta_description',
                ], true),
            )?->meta_value ?? ''
        ));

        $googleSerpPreview = app(ArticleGoogleSerpPreviewService::class)->buildForArticle(
            $article,
            $seoTitle,
            $seoDescription,
            app(WordPressArticleContentService::class)->resolvePermalink($article) ?: '',
        );

        return [
            'focus_keyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article),
            'site_domain' => trim((string) ($article->site?->domain ?? '')),
            'article_type' => (string) ($article->type ?? 'post'),
            'skip_seo_score' => $skipSeoScore,
            'score' => $skipSeoScore || $article->seo_score === null
                ? null
                : (int) round((float) $article->seo_score),
            'analysis' => is_array($analysis) ? $analysis : null,
            'content_bonus' => $contentBonus,
            'extracted_links' => $extractedLinks,
            'suggested_internal_links' => $suggestedInternalLinks,
            'suggested_internal_links_catalog' => $suggestedInternalLinksCatalog,
            'google_serp_preview' => $googleSerpPreview,
            'article_slug' => trim((string) ($article->slug ?? '')),
            'permalink_base' => $article->site
                ? rtrim(app(WordPressArticleContentService::class)->getPermalinkBase($article->site), '/')
                : '',
            'domain_link_list_catalog' => app(DomainLinkListEditorService::class)->forSite($article->site),
            'domain_link_list' => app(DomainLinkListEditorService::class)->forArticle($article, $bodyHtml),
            'domain_cta_list' => app(DomainCtaEditorService::class)->forSite($article->site),
            'seo_scoring_messages' => SeoEngineService::scoringMessagesForLocale(),
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
