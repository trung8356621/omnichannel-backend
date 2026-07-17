<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\RankMathSeoValueNormalizer;
use App\Addons\SeoContentAi\Support\WordPressPermalinkBuilder;
use Illuminate\Support\Str;

/**
 * Lưu nhanh trường SEO từ modal Google preview — không Livewire, không sync WP slug, chấm điểm qua queue.
 */
final class ArticleEditorSeoMetaService
{
    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly ArticleGoogleSerpPreviewService $googleSerpPreview,
        private readonly WordPressArticleContentService $wpContent,
        private readonly WordPressPermalinkBuilder $permalinkBuilder,
        private readonly SeoArticleScoringQueueService $scoringQueue,
    ) {}

    /**
     * @return array{
     *     google_serp_preview: array<string, mixed>,
     *     score: int|null,
     *     focus_keyword: string,
     *     article_slug: string,
     *     permalink: string,
     *     permalink_base: string,
     *     permalink_suffix: string,
     *     meta_description: string,
     *     seo_analysis_pending: bool
     * }
     */
    public function save(
        SeoArticle $article,
        string $focusKeyword,
        string $metaDescription,
        string $slug = '',
    ): array {
        $focusKeyword = trim($focusKeyword);
        $metaDescription = trim($metaDescription);
        $normalizedSlug = Str::slug(trim($slug));

        $this->bundleApply->applySeoMetaOnly($article, $focusKeyword, $metaDescription);
        $this->persistSlugLocalOnly($article, $normalizedSlug);
        $this->clearInvalidRankMathSeoTitleMeta($article);

        $article = $article->fresh(['articleMetas', 'site']) ?? $article;

        $this->scoringQueue->dispatchForArticle($article, force: true);

        return $this->buildResponse($article, $focusKeyword, $metaDescription, $normalizedSlug);
    }

    private function persistSlugLocalOnly(SeoArticle $article, string $normalizedSlug): void
    {
        if ($normalizedSlug === '') {
            return;
        }

        $previousSlug = trim((string) ($article->slug ?? ''));
        if ($normalizedSlug === $previousSlug) {
            return;
        }

        $article->update(['slug' => $normalizedSlug]);
        $this->syncFlags->markLocalEditPending($article->fresh() ?? $article);
    }

    private function clearInvalidRankMathSeoTitleMeta(SeoArticle $article): void
    {
        $article->loadMissing('articleMetas');

        $rawSeoTitle = trim((string) ($article->articleMetas->firstWhere('meta_key', 'seo_title')?->meta_value ?? ''));
        if ($rawSeoTitle === '') {
            return;
        }

        if (RankMathSeoValueNormalizer::normalizeTitle($rawSeoTitle) !== null) {
            return;
        }

        $article->articleMetas()->where('meta_key', 'seo_title')->delete();
    }

    /**
     * @return array{
     *     google_serp_preview: array<string, mixed>,
     *     score: int|null,
     *     focus_keyword: string,
     *     article_slug: string,
     *     permalink: string,
     *     permalink_base: string,
     *     permalink_suffix: string,
     *     meta_description: string,
     *     seo_analysis_pending: bool
     * }
     */
    private function buildResponse(
        SeoArticle $article,
        string $focusKeyword,
        string $metaDescription,
        string $normalizedSlug,
    ): array {
        $articleSlug = $normalizedSlug !== ''
            ? $normalizedSlug
            : trim((string) ($article->slug ?? ''));

        $displaySlug = $articleSlug !== '' ? $articleSlug : Str::slug(trim((string) ($article->title ?? '')));
        $permalink = $this->resolveDisplayPermalink($article, $displaySlug);
        $seoTitle = trim((string) ($article->title ?? ''));

        $preview = $this->googleSerpPreview->buildForArticle(
            $article,
            $seoTitle,
            $metaDescription,
            $permalink,
        );

        $score = $article->seo_score !== null ? (int) round((float) $article->seo_score) : null;

        $permalinkBase = $this->resolvePermalinkBase($article);

        return [
            'google_serp_preview' => $preview,
            'score' => $score,
            'focus_keyword' => $focusKeyword,
            'article_slug' => $articleSlug,
            'permalink' => $permalink,
            'permalink_base' => $permalinkBase,
            'permalink_suffix' => $this->resolvePermalinkSuffix($permalink, $articleSlug),
            'meta_description' => $metaDescription,
            'seo_analysis_pending' => true,
        ];
    }

    private function resolvePermalinkBase(SeoArticle $article): string
    {
        $article->loadMissing('site');
        if (! $article->site) {
            return '';
        }

        return $this->wpContent->getPermalinkBase($article->site);
    }

    /**
     * URL hiển thị theo slug local mới — không giữ permalink WP cũ (chưa Sync).
     */
    private function resolveDisplayPermalink(SeoArticle $article, string $displaySlug): string
    {
        $displaySlug = trim($displaySlug);
        if ($displaySlug === '') {
            return '';
        }

        $preview = $this->permalinkBuilder->preview($article, $displaySlug);
        if ($preview !== '') {
            return $preview;
        }

        $base = $this->resolvePermalinkBase($article);

        return $base !== ''
            ? rtrim($base, '/').'/'.$displaySlug
            : '';
    }

    private function resolvePermalinkSuffix(string $permalink, string $slug): string
    {
        $permalink = trim($permalink);
        $slug = trim($slug);

        if ($permalink === '' || $slug === '') {
            return '';
        }

        $path = (string) parse_url($permalink, PHP_URL_PATH);
        $basename = trim((string) basename($path));
        if ($basename === '') {
            return '';
        }

        $prefix = $slug.'.';
        if (str_starts_with($basename, $prefix)) {
            return substr($basename, strlen($slug));
        }

        return '';
    }
}
