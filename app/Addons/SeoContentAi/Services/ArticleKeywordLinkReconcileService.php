<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\KeywordFocusAttach;

/**
 * Đối chiếu từ khóa ↔ liên kết outbound khi bài viết được cập nhật / đồng bộ nội dung.
 */
final class ArticleKeywordLinkReconcileService
{
    /**
     * @param  array<int, string>  $excludeAnchorPhrases
     */
    public function reconcileForArticle(
        SeoArticle $article,
        ?string $contentOverride = null,
        array $excludeAnchorPhrases = [],
    ): void {
        $article->loadMissing(['site', 'articleMetas']);

        if ($this->isTaxonomyArticle($article)) {
            return;
        }

        $content = $contentOverride ?? $this->resolveArticleContent($article);

        app(SeoAnalyzerService::class)->reconcileKeywordLinksFromContent(
            $article,
            $content,
            $article->site?->domain,
            $excludeAnchorPhrases,
        );

        $this->refreshMainKeywordDestinationLink($article);
    }

    public function resolveArticleContent(SeoArticle $article): string
    {
        $body = trim((string) ($article->body ?? ''));
        if ($body !== '') {
            return $body;
        }

        $article->loadMissing('articleMetas');
        $metaContent = trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_post_content')?->meta_value ?? ''));

        return $metaContent;
    }

    private function refreshMainKeywordDestinationLink(SeoArticle $article): void
    {
        $article->loadMissing(['articleMetas', 'site']);

        $focusKeyword = trim((string) ($article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''));
        if ($focusKeyword === '') {
            return;
        }

        $siteId = (int) ($article->site_id ?? 0);
        $userId = (int) (auth()->id() ?? $article->user_id ?? $article->site?->user_id ?? 0);
        if ($siteId <= 0 || $userId <= 0) {
            return;
        }

        KeywordFocusAttach::syncMainKeyword($article, $siteId, $userId, $focusKeyword);
    }

    private function isTaxonomyArticle(SeoArticle $article): bool
    {
        return app(WordPressArticleContentService::class)->isTaxonomyRecord($article);
    }
}
