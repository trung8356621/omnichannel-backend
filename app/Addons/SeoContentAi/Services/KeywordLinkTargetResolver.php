<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoArticleLink;
use App\Models\Site;
use Illuminate\Support\Collection;

final class KeywordLinkTargetResolver
{
    public function __construct(
        private readonly WordPressArticleContentService $wpContent,
    ) {}

    /**
     * URL đích khi gợi ý chèn link — gộp mọi keyword cùng phrase (focus + internal).
     *
     * @return array{href: string, keyword_id: int, keyword_type: string}|null
     */
    public function resolveForPhraseOnSite(int $siteId, string $phrase, SeoArticle $currentArticle): ?array
    {
        $phrase = trim($phrase);
        if ($siteId <= 0 || $phrase === '') {
            return null;
        }

        $normalized = mb_strtolower($phrase);

        /** @var Collection<int, Keyword> $keywords */
        $keywords = Keyword::query()
            ->where('site_id', $siteId)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->get()
            ->filter(fn (Keyword $keyword): bool => mb_strtolower(trim((string) $keyword->phrase)) === $normalized)
            ->sortBy(fn (Keyword $keyword): int => $keyword->type === Keyword::TYPE_INTERNAL ? 0 : 1)
            ->values();

        foreach ($keywords as $keyword) {
            $href = $this->resolveForKeyword($keyword, $currentArticle);
            if ($href !== null && $href !== '') {
                return [
                    'href' => $href,
                    'keyword_id' => (int) $keyword->id,
                    'keyword_type' => (string) $keyword->type,
                ];
            }
        }

        return null;
    }

    /**
     * @deprecated Dùng {@see resolveForPhraseOnSite()} khi phrase có thể trùng nhiều type.
     */
    public function resolveForFocusKeyword(Keyword $keyword, SeoArticle $currentArticle): ?string
    {
        return $this->resolveForKeyword($keyword, $currentArticle);
    }

    public function resolveForKeyword(Keyword $keyword, SeoArticle $currentArticle): ?string
    {
        $explicit = trim((string) ($keyword->target_url ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        if ($keyword->type === Keyword::TYPE_INTERNAL) {
            $fromLinks = $this->resolveFromInternalKeywordLinks($keyword, $currentArticle);
            if ($fromLinks !== null) {
                return $fromLinks;
            }
        }

        $targetArticle = $keyword->articles()
            ->where('articles.id', '!=', (int) $currentArticle->id)
            ->orderByPivot('is_main', 'desc')
            ->orderBy('articles.id')
            ->first();

        if ($targetArticle instanceof SeoArticle) {
            return $this->resolveArticlePublicUrl($targetArticle);
        }

        $mainArticle = $keyword->mainArticles()
            ->where('articles.id', '!=', (int) $currentArticle->id)
            ->first();

        if ($mainArticle instanceof SeoArticle) {
            return $this->resolveArticlePublicUrl($mainArticle);
        }

        return null;
    }

    public function resolveArticlePublicUrl(SeoArticle $article): ?string
    {
        $permalink = trim($this->wpContent->resolvePermalink($article));
        if ($permalink !== '') {
            return $permalink;
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return null;
        }

        $base = $this->wpContent->getPermalinkBase($site);
        $slug = trim((string) ($article->slug ?? ''));
        if ($base === '' || $slug === '') {
            return null;
        }

        return rtrim($base, '/') . '/' . ltrim($slug, '/');
    }

    private function resolveFromInternalKeywordLinks(Keyword $keyword, SeoArticle $currentArticle): ?string
    {
        $currentPermalink = $this->normalizeUrlForCompare(
            $this->resolveArticlePublicUrl($currentArticle) ?? '',
        );

        $urls = SeoArticleLink::query()
            ->where('keyword_id', $keyword->id)
            ->where('type', 'internal')
            ->whereNotNull('url')
            ->where('url', '!=', '')
            ->orderBy('id')
            ->pluck('url');

        foreach ($urls as $url) {
            $trimmed = trim((string) $url);
            if ($trimmed === '') {
                continue;
            }

            if ($currentPermalink !== '' && $this->normalizeUrlForCompare($trimmed) === $currentPermalink) {
                continue;
            }

            return $trimmed;
        }

        $linkedArticle = $keyword->articlesViaInternalLink()
            ->where('articles.id', '!=', (int) $currentArticle->id)
            ->orderBy('articles.id')
            ->first();

        if ($linkedArticle instanceof SeoArticle) {
            return $this->resolveArticlePublicUrl($linkedArticle);
        }

        return null;
    }

    private function normalizeUrlForCompare(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }

        return rtrim(strtolower($trimmed), '/');
    }
}
