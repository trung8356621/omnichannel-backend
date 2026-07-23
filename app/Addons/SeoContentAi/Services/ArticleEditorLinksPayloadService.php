<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;

/**
 * Links sidebar payload split out of ArticleEditorSeoPayloadService::forArticle()
 * (Phase 2 perf) — the Links panel must never call the full forArticle() bundle
 * (violations/score/analysis/SERP preview are irrelevant to it).
 */
final class ArticleEditorLinksPayloadService
{
    public function __construct(
        private readonly ArticleInternalLinkSuggestionService $suggestionService,
    ) {}

    /**
     * Extracted links (from body) + domain link/CTA lists — no keyword suggestions.
     *
     * @return array<string, mixed>
     */
    public function base(SeoArticle $article): array
    {
        $article->loadMissing('site');
        $bodyHtml = (string) ($article->body ?? '');
        $extractedLinks = $article->resolveExtractedLinks();

        return [
            'extracted_links' => $extractedLinks,
            'domain_link_list' => app(DomainLinkListEditorService::class)->forArticle($article, $bodyHtml),
            'domain_link_list_catalog' => app(DomainLinkListEditorService::class)->forSite($article->site),
            'domain_cta_list' => app(DomainCtaEditorService::class)->forSite($article->site),
            'suggested_internal_links' => [],
            'suggested_internal_links_catalog' => [],
            'suggested_external_links' => [],
            'suggested_external_links_catalog' => [],
            'can_generate_suggestions' => true,
            'counts' => [
                'internal' => count($extractedLinks['internal'] ?? []),
                'external' => count($extractedLinks['external'] ?? []),
            ],
        ];
    }

    /**
     * base() + one suggestBundle() pass for internal/external keyword suggestions.
     *
     * @return array<string, mixed>
     */
    public function withSuggestions(SeoArticle $article, string $content): array
    {
        $base = $this->base($article);
        $internalLinks = $base['extracted_links']['internal'] ?? [];
        $externalLinks = $base['extracted_links']['external'] ?? [];

        $bundle = $this->suggestionService->suggestBundle($article, $content, $internalLinks, $externalLinks);

        return array_merge($base, [
            'suggested_internal_links' => $bundle['internal'],
            'suggested_internal_links_catalog' => $bundle['internal_catalog'],
            'suggested_external_links' => $bundle['external'],
            'suggested_external_links_catalog' => $bundle['external_catalog'],
        ]);
    }
}
