<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\SeoRuleViolationsResolver;
use App\Addons\SeoContentAi\Support\SeoScoringRulesRegistry;

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

        $violations = SeoRuleViolationsResolver::forArticle($article);
        $score = SeoRuleViolationsResolver::scoreForArticle($article);
        $extractedLinks = $article->resolveExtractedLinks();
        $bodyHtml = (string) ($article->body ?? '');
        $internalLinks = $extractedLinks['internal'] ?? [];
        $externalLinks = $extractedLinks['external'] ?? [];
        $suggestionService = app(ArticleInternalLinkSuggestionService::class);
        $suggestedInternalLinks = $suggestionService->suggest(
            $article,
            $bodyHtml,
            $internalLinks,
            $externalLinks,
        );
        $suggestedInternalLinksCatalog = $suggestionService->suggestCatalog(
            $article,
            $bodyHtml,
            $internalLinks,
            $externalLinks,
        );
        $contentBonus = $this->contentBonus->resolveForArticle($article);

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

        $analysis = [
            'violations' => $violations,
            'score' => $score,
        ];

        return [
            'focus_keyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article),
            'site_domain' => trim((string) ($article->site?->domain ?? '')),
            'article_type' => (string) ($article->type ?? 'post'),
            'skip_seo_score' => $skipSeoScore,
            'violations' => $violations,
            'score' => $skipSeoScore ? null : $score,
            'analysis' => $analysis,
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
            'seo_scoring_rules' => SeoScoringRulesRegistry::publicRulesForClient(),
            'seo_rule_messages' => SeoScoringRulesRegistry::messagesForLocale(),
            'seo_scoring_messages' => SeoScoringRulesRegistry::messagesForLocale(),
        ];
    }
}
