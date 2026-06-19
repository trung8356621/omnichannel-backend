<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoLink;
use App\Models\Site;
use Illuminate\Support\Collection;

final class KeywordLinkTargetResolver
{
    public function __construct(
        private readonly WordPressArticleContentService $wpContent,
        private readonly SitePolylangService $polylang,
    ) {}

    /**
     * @return array{href: string, keyword_id: int, keyword_type: string}|null
     */
    public function resolveForPhraseOnSite(
        int $siteId,
        string $phrase,
        SeoArticle $currentArticle,
        bool $sameLanguageOnly = false,
    ): ?array {
        $phrase = trim($phrase);
        if ($siteId <= 0 || $phrase === '') {
            return null;
        }

        $normalized = mb_strtolower($phrase);

        /** @var Collection<int, Keyword> $keywords */
        $keywords = Keyword::query()
            ->forSite($siteId)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->with(['links' => static fn ($query) => $query->where('seo_links.site_id', $siteId)])
            ->get()
            ->filter(fn (Keyword $keyword): bool => mb_strtolower(trim((string) $keyword->phrase)) === $normalized)
            ->sortBy(fn (Keyword $keyword): int => Keyword::isNormalType($keyword->type) ? 0 : 1)
            ->values();

        foreach ($keywords as $keyword) {
            $href = $this->resolveForKeyword($keyword, $currentArticle, $sameLanguageOnly);
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

    public function resolveForFocusKeyword(Keyword $keyword, SeoArticle $currentArticle): ?string
    {
        return $this->resolveForKeyword($keyword, $currentArticle);
    }

    public function resolveForKeyword(Keyword $keyword, SeoArticle $currentArticle, bool $sameLanguageOnly = false): ?string
    {
        $siteId = (int) ($currentArticle->site_id ?? 0);
        $currentLang = $this->articleLanguage($currentArticle);
        $explicit = trim((string) ($keyword->targetUrlForSite($siteId) ?? ''));
        if ($explicit !== '') {
            if (! $sameLanguageOnly || $this->urlMatchesArticleLanguage($siteId, $explicit, $currentLang)) {
                return $explicit;
            }
        }

        if (Keyword::isNormalType($keyword->type)) {
            $fromLinks = $this->resolveFromInternalKeywordLinks($keyword, $currentArticle, $sameLanguageOnly);
            if ($fromLinks !== null) {
                return $fromLinks;
            }
        }

        $targetArticle = $keyword->articles()
            ->where('articles.id', '!=', (int) $currentArticle->id)
            ->when($sameLanguageOnly, static fn ($query) => $query->where('articles.language', $currentLang))
            ->orderByPivot('is_main', 'desc')
            ->orderBy('articles.id')
            ->first();

        if ($targetArticle instanceof SeoArticle) {
            return $this->resolveArticlePublicUrl($targetArticle);
        }

        $mainArticle = $keyword->mainArticles()
            ->where('articles.id', '!=', (int) $currentArticle->id)
            ->when($sameLanguageOnly, static fn ($query) => $query->where('articles.language', $currentLang))
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

        return rtrim($base, '/').'/'.ltrim($slug, '/');
    }

    private function resolveFromInternalKeywordLinks(
        Keyword $keyword,
        SeoArticle $currentArticle,
        bool $sameLanguageOnly = false,
    ): ?string {
        $siteId = (int) ($currentArticle->site_id ?? 0);
        $currentLang = $this->articleLanguage($currentArticle);
        $currentPermalink = $this->normalizeUrlForCompare(
            $this->resolveArticlePublicUrl($currentArticle) ?? '',
        );

        $urls = $keyword->links()
            ->where('seo_links.type', SeoLink::TYPE_INTERNAL)
            ->where('seo_links.site_id', $siteId)
            ->orderBy('seo_links.id')
            ->pluck('seo_links.url');

        foreach ($urls as $url) {
            $trimmed = trim((string) $url);
            if ($trimmed === '') {
                continue;
            }

            if ($currentPermalink !== '' && $this->normalizeUrlForCompare($trimmed) === $currentPermalink) {
                continue;
            }

            if ($sameLanguageOnly && ! $this->urlMatchesArticleLanguage($siteId, $trimmed, $currentLang)) {
                continue;
            }

            return $trimmed;
        }

        $linkedArticle = SeoArticle::query()
            ->where('site_id', $siteId)
            ->where('id', '!=', (int) $currentArticle->id)
            ->when($sameLanguageOnly, static fn ($query) => $query->where('language', $currentLang))
            ->whereIn('id', function ($query) use ($keyword): void {
                $query->select('source_article_id')
                    ->from('seo_links')
                    ->join('keyword_link', 'keyword_link.link_id', '=', 'seo_links.id')
                    ->where('keyword_link.keyword_id', $keyword->id)
                    ->where('seo_links.type', SeoLink::TYPE_INTERNAL)
                    ->whereNotNull('seo_links.source_article_id');
            })
            ->orderBy('id')
            ->first();

        if ($linkedArticle instanceof SeoArticle) {
            return $this->resolveArticlePublicUrl($linkedArticle);
        }

        return null;
    }

    private function articleLanguage(SeoArticle $article): string
    {
        $lang = trim((string) ($article->language ?? ''));

        return $lang !== '' ? $lang : 'vi';
    }

    private function urlMatchesArticleLanguage(int $siteId, string $url, string $language): bool
    {
        $site = $siteId > 0 ? Site::query()->find($siteId) : null;

        $article = $this->findArticleByPublicUrl($siteId, $url);
        if ($article instanceof SeoArticle) {
            return $this->articleLanguage($article) === $language;
        }

        $inferredLang = $this->inferLanguageFromUrlPath($site instanceof Site ? $site : null, $url);
        if ($inferredLang !== null) {
            return $inferredLang === $language;
        }

        // Link list / category không có prefix ngôn ngữ — vẫn cho phép.
        return true;
    }

    private function inferLanguageFromUrlPath(?Site $site, string $url): ?string
    {
        if (! $site instanceof Site || ! $this->polylang->isPolylangEnabledForSite($site)) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            if (! str_starts_with(trim($url), '/')) {
                return null;
            }

            $path = trim($url);
        }

        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        $languageSlugs = array_keys($this->polylang->languageOptionsForSite($site));
        if ($languageSlugs === []) {
            return $this->polylang->defaultLanguageSlugForSite($site);
        }

        $defaultLang = $this->polylang->defaultLanguageSlugForSite($site);
        if ($segments === []) {
            return $defaultLang;
        }

        $first = strtolower($segments[0]);
        if (in_array($first, $languageSlugs, true)) {
            return $first;
        }

        return $defaultLang;
    }

    private function findArticleByPublicUrl(int $siteId, string $url): ?SeoArticle
    {
        if ($siteId <= 0) {
            return null;
        }

        $normalizedTarget = $this->normalizeUrlForCompare($url);
        if ($normalizedTarget === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            if (str_starts_with(trim($url), '/')) {
                $path = trim($url);
            } else {
                return null;
            }
        }

        $slug = $this->extractSlugFromPath($path);
        if ($slug === '') {
            return null;
        }

        $site = Site::query()->find($siteId);
        $inferredLang = $this->inferLanguageFromUrlPath($site instanceof Site ? $site : null, $url);

        $candidatesQuery = SeoArticle::query()
            ->where('site_id', $siteId)
            ->where('slug', $slug);

        if ($inferredLang !== null) {
            $candidatesQuery->where('language', $inferredLang);
        }

        $candidates = $candidatesQuery->limit(10)->get();

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof SeoArticle) {
                continue;
            }

            $permalink = $this->resolveArticlePublicUrl($candidate);
            if ($permalink !== null && $this->normalizeUrlForCompare($permalink) === $normalizedTarget) {
                return $candidate;
            }
        }

        /** @var SeoArticle|null $first */
        $first = $candidates->first();

        return $first instanceof SeoArticle ? $first : null;
    }

    private function extractSlugFromPath(string $path): string
    {
        $slug = basename(rtrim($path, '/'));
        if ($slug === '' || $slug === '/') {
            return '';
        }

        return (string) (preg_replace('/\.html?$/i', '', $slug) ?? $slug);
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
