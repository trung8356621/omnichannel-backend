<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Enums\KeywordMetaKey;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\KeywordMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\KeywordPhraseMatcher;

final class ArticleInternalLinkSuggestionService
{
    private const MAX_INTERNAL_LINKS = 10;

    public function __construct(
        private readonly KeywordLinkTargetResolver $linkTargetResolver,
    ) {}

    /**
     * Gợi ý từ khóa focus có trong bài nhưng chưa là link nội bộ (khi số link nội bộ &lt; 10).
     *
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @return list<array{text: string, keyword_id: int, href: string|null, target_url: string|null, can_insert: bool, is_suggestion: true}>
     */
    public function suggest(SeoArticle $article, string $content, array $internalLinks): array
    {
        if (count($internalLinks) >= self::MAX_INTERNAL_LINKS) {
            return [];
        }

        $maxSuggestions = self::MAX_INTERNAL_LINKS - count($internalLinks);

        return array_slice(
            $this->collectCandidates($article, $content, $internalLinks),
            0,
            max(0, $maxSuggestions),
        );
    }

    /**
     * Toàn bộ từ khóa trong bài có thể gợi ý (không giới hạn số dòng hiển thị).
     * Dùng cho client exclude / refill danh sách gợi ý.
     *
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @return list<array{text: string, keyword_id: int, href: string|null, target_url: string|null, can_insert: bool, is_suggestion: true}>
     */
    public function suggestCatalog(SeoArticle $article, string $content, array $internalLinks): array
    {
        return $this->collectCandidates($article, $content, $internalLinks);
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @return list<array{text: string, keyword_id: int, href: string|null, target_url: string|null, can_insert: bool, is_suggestion: true}>
     */
    private function collectCandidates(SeoArticle $article, string $content, array $internalLinks): array
    {
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return [];
        }

        $plainText = $this->plainTextFromHtml($content);
        if ($plainText === '') {
            return [];
        }

        $linkedContext = $this->collectLinkedContext($internalLinks);
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

        $keywordsQuery = Keyword::query()
            ->forSite($siteId)
            ->where('type', Keyword::TYPE_NORMAL)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->orderByRaw('CHAR_LENGTH(phrase) DESC');

        if ($excludeKeywordIds->isNotEmpty()) {
            $keywordsQuery->whereNotIn('id', $excludeKeywordIds);
        }

        $keywords = $keywordsQuery->get(['id', 'phrase']);

        $suggestions = [];

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

            $resolved = $this->linkTargetResolver->resolveForPhraseOnSite($siteId, $phrase, $article, sameLanguageOnly: true);
            $href = $resolved['href'] ?? null;
            $keywordId = (int) ($resolved['keyword_id'] ?? $keyword->id);

            if ($href !== null && $href !== '' && $this->isHrefAlreadyLinked($href, $linkedHrefs)) {
                continue;
            }

            $suggestions[] = [
                'text' => $phrase,
                'keyword_id' => $keywordId,
                'href' => $href,
                'target_url' => $href,
                'can_insert' => $href !== null && $href !== '',
                'is_suggestion' => true,
            ];

            $linkedLabels[] = mb_strtolower($phrase);
            if ($href !== null && $href !== '') {
                $normalizedHref = $this->normalizeHrefForCompare($href);
                if ($normalizedHref !== '') {
                    $linkedHrefs[] = $normalizedHref;
                }
            }
        }

        return $suggestions;
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
