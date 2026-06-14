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
    ) {}

    /**
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
            ->forSite($siteId)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->with(['links' => static fn ($query) => $query->where('seo_links.site_id', $siteId)])
            ->get()
            ->filter(fn (Keyword $keyword): bool => mb_strtolower(trim((string) $keyword->phrase)) === $normalized)
            ->sortBy(fn (Keyword $keyword): int => Keyword::isNormalType($keyword->type) ? 0 : 1)
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

    public function resolveForFocusKeyword(Keyword $keyword, SeoArticle $currentArticle): ?string
    {
        return $this->resolveForKeyword($keyword, $currentArticle);
    }

    public function resolveForKeyword(Keyword $keyword, SeoArticle $currentArticle): ?string
    {
        $siteId = (int) ($currentArticle->site_id ?? 0);
        $explicit = trim((string) ($keyword->targetUrlForSite($siteId) ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        if (Keyword::isNormalType($keyword->type)) {
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

        return rtrim($base, '/').'/'.ltrim($slug, '/');
    }

    private function resolveFromInternalKeywordLinks(Keyword $keyword, SeoArticle $currentArticle): ?string
    {
        $currentPermalink = $this->normalizeUrlForCompare(
            $this->resolveArticlePublicUrl($currentArticle) ?? '',
        );

        $urls = $keyword->links()
            ->where('seo_links.type', SeoLink::TYPE_INTERNAL)
            ->where('seo_links.site_id', (int) ($currentArticle->site_id ?? 0))
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

            return $trimmed;
        }

        $linkedArticle = SeoArticle::query()
            ->where('site_id', (int) ($currentArticle->site_id ?? 0))
            ->where('id', '!=', (int) $currentArticle->id)
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

    private function normalizeUrlForCompare(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }

        return rtrim(strtolower($trimmed), '/');
    }
}
