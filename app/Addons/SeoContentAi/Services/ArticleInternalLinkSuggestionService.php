<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Enums\KeywordMetaKey;
use App\Addons\SeoContentAi\Enums\KeywordReviewStatus;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\KeywordMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\KeywordPhraseMatcher;
use App\Addons\SeoContentAi\Support\SeoLinkMapLinkTypeClassifier;

final class ArticleInternalLinkSuggestionService
{
    private const MAX_INTERNAL_LINKS = 10;

    private const MAX_SUGGESTION_DISPLAY = 10;

    private const MAX_EXTERNAL_SUGGESTION_DISPLAY = 10;

    /**
     * Request-scoped cache: same (article, content, links) collectCandidates() call
     * repeated by suggest()/suggestCatalog()/suggestExternal()/suggestExternalCatalog()
     * within one request only pays the query cost once (Phase 2 perf).
     *
     * @var array<string, array{internal: list<array<string, mixed>>, external: list<array<string, mixed>>}>
     */
    private array $candidatesCache = [];

    /**
     * Request-scoped cache of the site keyword catalog, keyed by site id + excluded
     * keyword ids — avoids re-running the full `Keyword::forSite()` scan per call.
     *
     * @var array<string, \Illuminate\Support\Collection<int, Keyword>>
     */
    private array $keywordsBySite = [];

    public function __construct(
        private readonly KeywordLinkTargetResolver $linkTargetResolver,
    ) {}

    /**
     * All four suggestion shapes (display + catalog, internal + external) from a
     * single collectCandidates() call — replaces 4 separate service calls that each
     * re-ran the same keyword scan (used by ArticleEditorSeoPayloadService::forArticle
     * and ArticleEditorLinksPayloadService::withSuggestions).
     *
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return array{
     *     internal: list<array<string, mixed>>,
     *     internal_catalog: list<array<string, mixed>>,
     *     external: list<array<string, mixed>>,
     *     external_catalog: list<array<string, mixed>>
     * }
     */
    public function suggestBundle(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        $candidates = $this->collectCandidates($article, $content, $internalLinks, $externalLinks);

        $internalCatalog = $candidates['internal'];
        $externalCatalog = $candidates['external'];

        return [
            'internal' => count($internalLinks) >= self::MAX_INTERNAL_LINKS
                ? []
                : array_slice($internalCatalog, 0, self::MAX_SUGGESTION_DISPLAY),
            'internal_catalog' => $internalCatalog,
            'external' => array_slice($externalCatalog, 0, self::MAX_EXTERNAL_SUGGESTION_DISPLAY),
            'external_catalog' => $externalCatalog,
        ];
    }

    /**
     * Gợi ý từ khóa focus có trong bài nhưng chưa là link nội bộ (khi số link nội bộ &lt; 10).
     * Chỉ URL trùng site_id bài viết (relative path hoặc host = domain site).
     *
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array{text: string, keyword_id: int, href: string|null, target_url: string|null, can_insert: bool, is_suggestion: true}>
     */
    public function suggest(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        if (count($internalLinks) >= self::MAX_INTERNAL_LINKS) {
            return [];
        }

        return array_slice(
            $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['internal'],
            0,
            self::MAX_SUGGESTION_DISPLAY,
        );
    }

    /**
     * Toàn bộ từ khóa trong bài có thể gợi ý internal (không giới hạn số dòng hiển thị).
     * Dùng cho client exclude / refill danh sách gợi ý.
     *
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array{text: string, keyword_id: int, href: string|null, target_url: string|null, can_insert: bool, is_suggestion: true}>
     */
    public function suggestCatalog(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        return $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['internal'];
    }

    /**
     * Gợi ý chèn external / wiki-trust (không gồm tel/mailto/…).
     *
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array{text: string, keyword_id: int, href: string|null, target_url: string|null, can_insert: bool, is_suggestion: true}>
     */
    public function suggestExternal(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        return array_slice(
            $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['external'],
            0,
            self::MAX_EXTERNAL_SUGGESTION_DISPLAY,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array{text: string, keyword_id: int, href: string|null, target_url: string|null, can_insert: bool, is_suggestion: true}>
     */
    public function suggestExternalCatalog(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        return $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['external'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return array{
     *     internal: list<array{text: string, keyword_id: int, href: string|null, target_url: string|null, can_insert: bool, is_suggestion: true}>,
     *     external: list<array{text: string, keyword_id: int, href: string|null, target_url: string|null, can_insert: bool, is_suggestion: true}>
     * }
     */
    private function collectCandidates(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        $empty = ['internal' => [], 'external' => []];
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return $empty;
        }

        $plainText = $this->plainTextFromHtml($content);
        if ($plainText === '') {
            return $empty;
        }

        $cacheKey = $this->candidatesCacheKey((int) $article->id, $content, $internalLinks, $externalLinks);
        if (isset($this->candidatesCache[$cacheKey])) {
            return $this->candidatesCache[$cacheKey];
        }

        $article->loadMissing('site');
        $siteDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost((string) ($article->site?->domain ?? ''));

        $linkedContext = $this->collectLinkedContext(array_merge($internalLinks, $externalLinks));
        $linkedLabels = $linkedContext['labels'];
        $linkedHrefs = $linkedContext['hrefs'];
        $ownArticlePhrases = $this->ownArticlePhraseBlocklist($article);

        $excludeKeywordIds = collect();
        $focusKeywordId = KeywordMeta::query()
            ->where('meta_key', KeywordMetaKey::MainArticleId->value)
            ->where('meta_value', (string) $article->id)
            ->value('keyword_id');
        if (is_numeric($focusKeywordId) && (int) $focusKeywordId > 0) {
            $excludeKeywordIds->push((int) $focusKeywordId);
        }

        $keywords = $this->keywordsForSite($siteId, $excludeKeywordIds->all());

        $internalSuggestions = [];
        $externalSuggestions = [];

        foreach ($keywords as $keyword) {
            $phrase = trim((string) $keyword->phrase);
            if ($phrase === '' || $this->isAlreadyLinked($phrase, $linkedLabels)) {
                continue;
            }

            if ($this->isOwnArticlePhrase($phrase, $ownArticlePhrases)) {
                continue;
            }

            if ($this->isMainKeywordOfCurrentArticle($keyword, $article)) {
                continue;
            }

            if (! $this->textContainsPhrase($plainText, $phrase)) {
                continue;
            }

            $resolvedInternal = $this->linkTargetResolver->resolveForPhraseOnSite(
                $siteId,
                $phrase,
                $article,
                sameLanguageOnly: true,
                internalOnly: true,
            );
            $resolvedAny = $resolvedInternal ?? $this->linkTargetResolver->resolveForPhraseOnSite(
                $siteId,
                $phrase,
                $article,
                sameLanguageOnly: true,
                internalOnly: false,
            );
            $href = is_array($resolvedAny) && is_string($resolvedAny['href'] ?? null)
                ? trim((string) $resolvedAny['href'])
                : '';
            $keywordId = (int) ((is_array($resolvedAny) ? ($resolvedAny['keyword_id'] ?? null) : null) ?? $keyword->id);

            if ($href !== '' && $this->isSpecialSchemeOrContactHref($href)) {
                continue;
            }

            if ($href !== '' && $this->isHrefAlreadyLinked($href, $linkedHrefs)) {
                continue;
            }

            $bucket = $this->suggestionBucketForHref($href, $siteDomain, $siteId);
            if ($bucket === null) {
                continue;
            }

            $item = [
                'text' => $phrase,
                'keyword_id' => $keywordId,
                'href' => $href !== '' ? $href : null,
                'target_url' => $href !== '' ? $href : null,
                'can_insert' => $href !== '',
                'is_suggestion' => true,
            ];

            if ($bucket === 'external') {
                if ($href === '') {
                    continue;
                }
                $externalSuggestions[] = $item;
            } else {
                $internalSuggestions[] = $item;
            }

            $linkedLabels[] = mb_strtolower($phrase);
            if ($href !== '') {
                $normalizedHref = $this->normalizeHrefForCompare($href);
                if ($normalizedHref !== '') {
                    $linkedHrefs[] = $normalizedHref;
                }
            }
        }

        $result = [
            'internal' => $internalSuggestions,
            'external' => $externalSuggestions,
        ];

        $this->candidatesCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     */
    private function candidatesCacheKey(int $articleId, string $content, array $internalLinks, array $externalLinks): string
    {
        return $articleId.':'.md5($content).':'.md5(serialize($internalLinks)).':'.md5(serialize($externalLinks));
    }

    /**
     * @param  list<int>  $excludeKeywordIds
     * @return \Illuminate\Support\Collection<int, Keyword>
     */
    private function keywordsForSite(int $siteId, array $excludeKeywordIds): \Illuminate\Support\Collection
    {
        $cacheKey = $siteId.':'.implode(',', $excludeKeywordIds);
        if (isset($this->keywordsBySite[$cacheKey])) {
            return $this->keywordsBySite[$cacheKey];
        }

        $keywordsQuery = Keyword::query()
            ->forSite($siteId)
            ->where('type', Keyword::TYPE_NORMAL)
            ->where('review_status', KeywordReviewStatus::Active->value)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->orderByRaw('CHAR_LENGTH(phrase) DESC');

        if ($excludeKeywordIds !== []) {
            $keywordsQuery->whereNotIn('id', $excludeKeywordIds);
        }

        $keywords = $keywordsQuery->get(['id', 'phrase']);
        $this->keywordsBySite[$cacheKey] = $keywords;

        return $keywords;
    }

    /**
     * @return 'internal'|'external'|null null = bỏ (tel/mail/…)
     */
    private function suggestionBucketForHref(string $href, string $siteDomain, int $siteId): ?string
    {
        if ($href === '') {
            // Chưa map URL — vẫn gợi ý dưới internal (từ khóa site, chờ gán bài đích nội bộ).
            return 'internal';
        }

        if ($this->isSpecialSchemeOrContactHref($href)) {
            return null;
        }

        if ($this->isInternalHrefForSite($href, $siteDomain, $siteId)) {
            return 'internal';
        }

        return 'external';
    }

    private function isInternalHrefForSite(string $href, string $siteDomain, int $siteId): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }

        if (str_starts_with($href, '/')) {
            return true;
        }

        $host = SeoLinkMapLinkTypeClassifier::resolveHost($href);
        if ($host !== '' && $siteDomain !== '' && $host === $siteDomain) {
            return true;
        }

        $targetArticle = $this->linkTargetResolver->resolveArticleFromUrl($siteId, $href);
        if ($targetArticle instanceof SeoArticle && (int) ($targetArticle->site_id ?? 0) === $siteId) {
            return true;
        }

        return false;
    }

    private function isSpecialSchemeOrContactHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }

        $lower = mb_strtolower($href);
        if (str_starts_with($lower, 'javascript:')) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if (is_string($scheme) && $scheme !== '') {
            return in_array(strtolower($scheme), [
                'tel',
                'mailto',
                'sms',
                'fax',
                'callto',
                'geo',
                'skype',
                'whatsapp',
                'viber',
                'data',
                'cid',
            ], true);
        }

        // Số điện thoại / email trần (không scheme) — không tính external.
        if (preg_match('/^[+]?[\d\s().-]{6,}$/u', $href) === 1) {
            return true;
        }

        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $href) === 1) {
            return true;
        }

        return false;
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @return array{labels: list<string>, hrefs: list<string>}
     */
    private function collectLinkedContext(array $internalLinks): array
    {
        $labels = [];
        $hrefs = [];

        foreach ($internalLinks as $link) {
            $text = trim((string) ($link['text'] ?? ''));
            if ($text !== '') {
                $labels[] = mb_strtolower($text);
            }

            $href = trim((string) ($link['href'] ?? ''));
            if ($href === '') {
                continue;
            }

            $normalizedHref = $this->normalizeHrefForCompare($href);
            if ($normalizedHref !== '') {
                $hrefs[] = $normalizedHref;
            }

            $path = parse_url($href, PHP_URL_PATH);
            if (! is_string($path) || $path === '') {
                continue;
            }

            $slug = basename($path);
            if ($slug !== '' && $slug !== '/') {
                $labels[] = mb_strtolower(str_replace(['-', '_'], ' ', $slug));
            }
        }

        return [
            'labels' => array_values(array_unique($labels)),
            'hrefs' => array_values(array_unique($hrefs)),
        ];
    }

    /**
     * @param  list<string>  $linkedHrefs
     */
    private function isHrefAlreadyLinked(string $href, array $linkedHrefs): bool
    {
        $normalized = $this->normalizeHrefForCompare($href);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $linkedHrefs, true);
    }

    private function normalizeHrefForCompare(string $href): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '') {
            return '';
        }

        if (str_starts_with($href, '/')) {
            $path = strtolower(rtrim($href, '/')) ?: '/';
            $query = '';
            if (str_contains($path, '?')) {
                [$path, $queryPart] = explode('?', $path, 2);
                $path = $path !== '' ? $path : '/';
                $query = '?'.strtolower($queryPart);
            }

            return $path.$query;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        $parsed = parse_url($href);
        if (! is_array($parsed)) {
            return strtolower(rtrim($href, '/'));
        }

        $path = strtolower(rtrim((string) ($parsed['path'] ?? ''), '/')) ?: '/';
        $query = isset($parsed['query']) && $parsed['query'] !== ''
            ? '?'.strtolower((string) $parsed['query'])
            : '';

        return $path.$query;
    }

    /**
     * @param  list<string>  $linkedLabels
     */
    private function isAlreadyLinked(string $phrase, array $linkedLabels): bool
    {
        $phraseLower = mb_strtolower(trim($phrase));
        if ($phraseLower === '') {
            return true;
        }

        foreach ($linkedLabels as $label) {
            if ($label === $phraseLower) {
                return true;
            }

            if (mb_stripos($label, $phraseLower) !== false || mb_stripos($phraseLower, $label) !== false) {
                return true;
            }
        }

        return false;
    }

    private function textContainsPhrase(string $text, string $phrase): bool
    {
        return KeywordPhraseMatcher::contains($text, $phrase);
    }

    /**
     * @return list<string> Cụm từ thuộc bài hiện tại (focus, title, keyword gắn bài).
     */
    private function ownArticlePhraseBlocklist(SeoArticle $article): array
    {
        $phrases = [];

        $focus = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article);
        if ($focus !== null) {
            $normalized = $this->normalizePhrase($focus);
            if ($normalized !== '') {
                $phrases[] = $normalized;
            }
        }

        $title = $this->normalizePhrase((string) ($article->title ?? ''));
        if ($title !== '') {
            $phrases[] = $title;
        }

        return array_values(array_unique($phrases));
    }

    /**
     * @param  list<string>  $ownArticlePhrases
     */
    private function isOwnArticlePhrase(string $phrase, array $ownArticlePhrases): bool
    {
        $normalized = $this->normalizePhrase($phrase);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $ownArticlePhrases, true);
    }

    private function isMainKeywordOfCurrentArticle(Keyword $keyword, SeoArticle $article): bool
    {
        return $keyword->mainArticles()
            ->where('articles.id', (int) $article->id)
            ->exists();
    }

    private function normalizePhrase(string $phrase): string
    {
        $phrase = mb_strtolower(trim($phrase));
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? '';

        return trim($phrase);
    }
}
