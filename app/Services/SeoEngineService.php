<?php

declare(strict_types=1);

namespace App\Services;

use App\Addons\SeoContentAi\Support\KeywordPhraseMatcher;
use App\Addons\SeoContentAi\Support\SeoLinkMapLinkTypeClassifier;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;

final class SeoEngineService
{
    private const MAX_HEADING = 20;

    private const MAX_LENGTH = 15;

    private const MAX_IMAGE_RATIO = 15;

    private const MAX_WIKI_TRUST = 15;

    private const MAX_FEATURED_SNIPPET = 10;

    private const MAX_FAQ_SCHEMA = 10;

    private const MAX_KEYWORD = 15;

    /**
     * @param  list<array{question?: string, answer?: string}>  $faqsMeta
     * @param  array{seo_title?: string, meta_description?: string, slug?: string, domain?: string}  $context
     * @return array{
     *   seo_score: int,
     *   score: int,
     *   reason_keys: list<string>,
     *   breakdown: array<string, array{max: int, earned: int, passed: bool, key: string}>,
     *   good: list<string>,
     *   errors: list<string>,
     *   warnings: list<string>
     * }
     */
    public function analyzeHtml(
        string $htmlContent,
        string $targetKeyword = '',
        array $faqsMeta = [],
        array $context = [],
    ): array {
        $keyword = $this->normalizeFocusKeyword($targetKeyword);
        $seoTitle = trim((string) ($context['seo_title'] ?? ''));
        $metaDescription = trim((string) ($context['meta_description'] ?? ''));
        $slug = trim((string) ($context['slug'] ?? ''));
        $domain = trim((string) ($context['domain'] ?? ''));

        if ($keyword === '') {
            return $this->buildResult(
                score: 0,
                reasonKeys: ['seo.missing_focus_keyword'],
                breakdown: [],
                good: [],
                errors: [__('seo.missing_focus_keyword')],
                warnings: [],
            );
        }

        $breakdown = [];
        $reasonKeys = [];
        $good = [];
        $errors = [];
        $warnings = [];
        $totalScore = 0;

        $heading = $this->scoreHeading($htmlContent);
        $breakdown['heading'] = $heading;
        $totalScore += $heading['earned'];
        $this->applyCategoryResult($heading, $good, $errors, $warnings, $reasonKeys);

        $length = $this->scoreLength($htmlContent);
        $breakdown['length'] = $length;
        $totalScore += $length['earned'];
        $this->applyCategoryResult($length, $good, $errors, $warnings, $reasonKeys, isPartial: $length['key'] === 'seo.length.partial');

        $imageRatio = $this->scoreTextToImage($htmlContent);
        $breakdown['image_ratio'] = $imageRatio;
        $totalScore += $imageRatio['earned'];
        $this->applyCategoryResult($imageRatio, $good, $errors, $warnings, $reasonKeys);

        $wikiTrust = $this->scoreWikiTrust($htmlContent, $domain);
        $breakdown['wiki_trust'] = $wikiTrust;
        $totalScore += $wikiTrust['earned'];
        $this->applyCategoryResult($wikiTrust, $good, $errors, $warnings, $reasonKeys);

        $featuredSnippet = $this->scoreFeaturedSnippet($htmlContent);
        $breakdown['featured_snippet'] = $featuredSnippet;
        $totalScore += $featuredSnippet['earned'];
        $this->applyCategoryResult($featuredSnippet, $good, $errors, $warnings, $reasonKeys);

        $faqSchema = $this->scoreFaqSchema($faqsMeta);
        $breakdown['faq_schema'] = $faqSchema;
        $totalScore += $faqSchema['earned'];
        $this->applyCategoryResult($faqSchema, $good, $errors, $warnings, $reasonKeys);

        $keywordScore = $this->scoreKeywordPlacement(
            $htmlContent,
            $keyword,
            $seoTitle,
            $metaDescription,
            $slug,
        );
        $breakdown['keyword'] = $keywordScore;
        $totalScore += $keywordScore['earned'];
        $this->applyCategoryResult($keywordScore, $good, $errors, $warnings, $reasonKeys);

        $score = max(0, min(100, $totalScore));

        return $this->buildResult($score, $reasonKeys, $breakdown, $good, $errors, $warnings);
    }

    /**
     * @return array<string, string>
     */
    public static function scoringMessagesForLocale(?string $locale = null): array
    {
        $previous = app()->getLocale();
        if ($locale !== null && $locale !== '') {
            app()->setLocale($locale);
        }

        $lines = [];
        foreach (array_keys((array) trans('seo')) as $key) {
            $lines['seo.'.$key] = (string) __("seo.{$key}");
        }

        if ($locale !== null && $locale !== '') {
            app()->setLocale($previous);
        }

        return $lines;
    }

    /**
     * @param  list<string>  $reasonKeys
     * @param  array<string, array{max: int, earned: int, passed: bool, key: string}>  $breakdown
     * @param  list<string>  $good
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @return array{
     *   seo_score: int,
     *   score: int,
     *   reason_keys: list<string>,
     *   breakdown: array<string, array{max: int, earned: int, passed: bool, key: string}>,
     *   good: list<string>,
     *   errors: list<string>,
     *   warnings: list<string>
     * }
     */
    private function buildResult(
        int $score,
        array $reasonKeys,
        array $breakdown,
        array $good,
        array $errors,
        array $warnings,
    ): array {
        return [
            'seo_score' => $score,
            'score' => $score,
            'reason_keys' => array_values(array_unique($reasonKeys)),
            'breakdown' => $breakdown,
            'good' => $good,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array{max: int, earned: int, passed: bool, key: string, message?: string, params?: array<string, mixed>}  $category
     * @param  list<string>  $good
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  list<string>  $reasonKeys
     */
    private function applyCategoryResult(
        array $category,
        array &$good,
        array &$errors,
        array &$warnings,
        array &$reasonKeys,
        bool $isPartial = false,
    ): void {
        $message = $this->resolveCategoryMessage($category);

        if ($category['passed'] && ! $isPartial) {
            $good[] = $message;

            return;
        }

        if ($isPartial) {
            $warnings[] = $message;
            $reasonKeys[] = $category['key'];

            return;
        }

        if ($category['earned'] > 0) {
            $warnings[] = $message;
        } else {
            $errors[] = $message;
        }

        $reasonKeys[] = $category['key'];
    }

    /**
     * @param  array{key: string, params?: array<string, mixed>}  $category
     */
    private function resolveCategoryMessage(array $category): string
    {
        if (isset($category['message']) && is_string($category['message']) && $category['message'] !== '') {
            return $category['message'];
        }

        $langKey = str_starts_with($category['key'], 'seo.')
            ? substr($category['key'], 4)
            : $category['key'];
        $template = (string) __("seo.{$langKey}");
        $params = is_array($category['params'] ?? null) ? $category['params'] : [];

        foreach ($params as $name => $value) {
            $template = str_replace(':'.$name, (string) $value, $template);
        }

        return $template;
    }

    /**
     * @return array{max: int, earned: int, passed: bool, key: string, params?: array<string, mixed>}
     */
    private function scoreHeading(string $html): array
    {
        $h2Count = $this->countH2Tags($html);
        $passed = $h2Count >= 2;

        return [
            'max' => self::MAX_HEADING,
            'earned' => $passed ? self::MAX_HEADING : 0,
            'passed' => $passed,
            'key' => $passed ? 'seo.heading.pass' : 'seo.heading',
            'params' => ['points' => self::MAX_HEADING],
        ];
    }

    /**
     * @return array{max: int, earned: int, passed: bool, key: string, params?: array<string, mixed>}
     */
    private function scoreLength(string $html): array
    {
        $wordCount = $this->countWords($html);

        if ($wordCount < 600) {
            return [
                'max' => self::MAX_LENGTH,
                'earned' => 0,
                'passed' => false,
                'key' => 'seo.length',
                'params' => ['count' => $wordCount, 'points' => 0, 'max' => self::MAX_LENGTH],
            ];
        }

        if ($wordCount <= 1200) {
            return [
                'max' => self::MAX_LENGTH,
                'earned' => 10,
                'passed' => false,
                'key' => 'seo.length.partial',
                'params' => ['count' => $wordCount, 'points' => 10, 'max' => self::MAX_LENGTH],
            ];
        }

        return [
            'max' => self::MAX_LENGTH,
            'earned' => self::MAX_LENGTH,
            'passed' => true,
            'key' => 'seo.length.pass',
            'params' => ['count' => $wordCount, 'points' => self::MAX_LENGTH],
        ];
    }

    /**
     * @return array{max: int, earned: int, passed: bool, key: string, params?: array<string, mixed>}
     */
    private function scoreTextToImage(string $html): array
    {
        $result = $this->calculateTextToImageScore($html);

        return [
            'max' => self::MAX_IMAGE_RATIO,
            'earned' => min(self::MAX_IMAGE_RATIO, max(0, (int) $result['score'])),
            'passed' => (int) $result['score'] >= self::MAX_IMAGE_RATIO,
            'key' => (int) $result['score'] >= self::MAX_IMAGE_RATIO ? 'seo.image_ratio.pass' : 'seo.image_ratio',
            'params' => [
                'ratio' => (int) ($result['ratio'] ?? 0),
                'points' => min(self::MAX_IMAGE_RATIO, max(0, (int) $result['score'])),
            ],
        ];
    }

    /**
     * @return array{max: int, earned: int, passed: bool, key: string, params?: array<string, mixed>}
     */
    private function scoreWikiTrust(string $html, string $domain): array
    {
        $passed = $this->hasWikiTrustExternalLink($html, $domain);

        return [
            'max' => self::MAX_WIKI_TRUST,
            'earned' => $passed ? self::MAX_WIKI_TRUST : 0,
            'passed' => $passed,
            'key' => $passed ? 'seo.wiki_trust.pass' : 'seo.wiki_trust',
            'params' => ['points' => self::MAX_WIKI_TRUST],
        ];
    }

    /**
     * @return array{max: int, earned: int, passed: bool, key: string, params?: array<string, mixed>}
     */
    private function scoreFeaturedSnippet(string $html): array
    {
        $passed = $this->hasFeaturedSnippetStructure($html);

        return [
            'max' => self::MAX_FEATURED_SNIPPET,
            'earned' => $passed ? self::MAX_FEATURED_SNIPPET : 0,
            'passed' => $passed,
            'key' => $passed ? 'seo.featured_snippet.pass' : 'seo.featured_snippet',
            'params' => ['points' => self::MAX_FEATURED_SNIPPET],
        ];
    }

    /**
     * @param  list<array{question?: string, answer?: string}>  $faqsMeta
     * @return array{max: int, earned: int, passed: bool, key: string, params?: array<string, mixed>}
     */
    private function scoreFaqSchema(array $faqsMeta): array
    {
        $passed = $this->hasFaqData($faqsMeta);

        return [
            'max' => self::MAX_FAQ_SCHEMA,
            'earned' => $passed ? self::MAX_FAQ_SCHEMA : 0,
            'passed' => $passed,
            'key' => $passed ? 'seo.faq_schema.pass' : 'seo.faq_schema',
            'params' => ['points' => self::MAX_FAQ_SCHEMA],
        ];
    }

    /**
     * @return array{max: int, earned: int, passed: bool, key: string, params?: array<string, mixed>}
     */
    private function scoreKeywordPlacement(
        string $html,
        string $keyword,
        string $seoTitle,
        string $metaDescription,
        string $slug,
    ): array {
        $inTitle = KeywordPhraseMatcher::contains($seoTitle, $keyword);
        $inMeta = KeywordPhraseMatcher::contains($metaDescription, $keyword);
        $inSlug = $this->slugContainsFocusKeyword($slug, $keyword);
        $inFirst100 = KeywordPhraseMatcher::contains($this->sliceFirstWords($html, 100), $keyword);

        $checks = [$inTitle, $inMeta, $inSlug, $inFirst100];
        $passedCount = count(array_filter($checks));
        $earned = (int) round(self::MAX_KEYWORD * ($passedCount / 4));
        $passed = $passedCount === 4;

        return [
            'max' => self::MAX_KEYWORD,
            'earned' => $passed ? self::MAX_KEYWORD : $earned,
            'passed' => $passed,
            'key' => $passed ? 'seo.keyword_density.pass' : 'seo.keyword_density',
            'params' => ['points' => $passed ? self::MAX_KEYWORD : $earned],
        ];
    }

    /**
     * @param  list<array{question?: string, answer?: string}>  $faqsMeta
     */
    private function hasFaqData(array $faqsMeta): bool
    {
        foreach ($faqsMeta as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));

            if ($question !== '' && $answer !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasFeaturedSnippetStructure(string $html): bool
    {
        if (trim($html) === '') {
            return false;
        }

        if ($this->hasTableNearStart($html)) {
            return true;
        }

        return $this->hasShortBulletListNearStart($html);
    }

    private function hasTableNearStart(string $html): bool
    {
        $leading = $this->extractLeadingContentHtml($html, 4000);

        return preg_match('/<table\b/i', $leading) === 1;
    }

    private function hasShortBulletListNearStart(string $html): bool
    {
        if (trim($html) === '') {
            return false;
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//body/*|/*');

        if ($nodes === false) {
            return false;
        }

        foreach ($nodes as $node) {
            $tag = strtolower($node->nodeName);
            if (in_array($tag, ['p', 'div', 'span', 'br'], true)) {
                $text = trim((string) $node->textContent);
                if ($text === '' && $tag !== 'div') {
                    continue;
                }
                if ($text === '' && $tag === 'div' && ! $node->hasChildNodes()) {
                    continue;
                }
            }

            if (! in_array($tag, ['ul', 'ol'], true)) {
                break;
            }

            $items = $xpath->query('.//li', $node);

            return $items !== false && $items->length > 0 && $items->length <= 5;
        }

        return false;
    }

    private function extractLeadingContentHtml(string $html, int $maxLength): string
    {
        $trimmed = ltrim($html);

        return mb_substr($trimmed, 0, max(1, $maxLength));
    }

    private function hasWikiTrustExternalLink(string $html, string $domain): bool
    {
        $pattern = '/<a\b[^>]*\bhref\s*=\s*(["\'])([^"\']+)\1/iu';
        if (preg_match_all($pattern, $html, $matches) === false) {
            return false;
        }

        foreach ($matches[2] as $href) {
            $href = trim(html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($href === '' || str_starts_with($href, '#') || $this->isSpecialSchemeLink($href)) {
                continue;
            }

            if ($this->isInternalLink($href, $domain)) {
                continue;
            }

            if (SeoLinkMapLinkTypeClassifier::forUnresolvedUrl($href) === \App\Addons\SeoContentAi\Enums\SeoLinkMapType::WikiTrust) {
                return true;
            }
        }

        return false;
    }

    private function isInternalLink(string $href, string $domain): bool
    {
        if (str_starts_with($href, '/')) {
            return true;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        $host = parse_url($href, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = SeoLinkMapLinkTypeClassifier::normalizeDomainHost($host);
        $normalizedDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost($domain);

        return $host !== '' && $normalizedDomain !== '' && $host === $normalizedDomain;
    }

    private function isSpecialSchemeLink(string $href): bool
    {
        $lower = strtolower($href);

        if (str_starts_with($lower, 'javascript:')) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if (! is_string($scheme) || $scheme === '') {
            return false;
        }

        return in_array(strtolower($scheme), [
            'tel', 'mailto', 'sms', 'fax', 'callto', 'geo', 'skype', 'whatsapp', 'viber', 'data', 'cid',
        ], true);
    }

    /**
     * @return array{score: int, ratio: int}
     */
    private function calculateTextToImageScore(string $htmlContent): array
    {
        if (trim($htmlContent) === '') {
            return ['score' => 0, 'ratio' => 0];
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $images = $dom->getElementsByTagName('img');
        $imageCount = $images->length;

        $textContent = strip_tags($htmlContent);
        $textContent = preg_replace('/\s+/u', ' ', trim($textContent)) ?? '';
        $wordCount = $textContent === '' ? 0 : count(array_filter(explode(' ', $textContent)));

        if ($wordCount < 10 || $imageCount === 0) {
            return ['score' => 0, 'ratio' => $wordCount];
        }

        $wordsPerImage = (int) round($wordCount / $imageCount);
        $score = 0;

        if ($wordsPerImage >= 250 && $wordsPerImage <= 450) {
            $score = 15;
        } elseif ($wordsPerImage > 450 && $wordsPerImage <= 800) {
            $score = 10;
        } elseif ($wordsPerImage < 250 && $wordsPerImage >= 100) {
            $score = 8;
        } else {
            $score = 3;
        }

        $missingAlt = 0;
        foreach ($images as $img) {
            if (trim((string) $img->getAttribute('alt')) === '') {
                $missingAlt++;
            }
        }

        if ($missingAlt > 0) {
            $score = max(0, $score - 5);
        }

        return ['score' => $score, 'ratio' => $wordsPerImage];
    }

    private function countH2Tags(string $html): int
    {
        if (trim($html) === '') {
            return 0;
        }

        if (preg_match_all('/<h2\b[^>]*>/iu', $html, $matches) === false) {
            return 0;
        }

        return count($matches[0] ?? []);
    }

    private function countWords(string $html): int
    {
        $text = trim(strip_tags($html));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        if ($text === '') {
            return 0;
        }

        preg_match_all('/\pL[\pL\pN\-]*/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    private function sliceFirstWords(string $html, int $wordLimit): string
    {
        $text = trim(strip_tags($html));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        if ($text === '') {
            return '';
        }

        preg_match_all('/\pL[\pL\pN\-]*/u', $text, $matches);
        $words = $matches[0] ?? [];

        return implode(' ', array_slice($words, 0, max(1, $wordLimit)));
    }

    private function slugContainsFocusKeyword(string $slug, string $focusKeyword): bool
    {
        $keywordSlug = Str::slug($this->normalizeFocusKeyword($focusKeyword));
        $articleSlug = Str::slug(trim($slug));

        if ($keywordSlug === '' || $articleSlug === '') {
            return false;
        }

        return str_contains($articleSlug, $keywordSlug);
    }

    private function normalizeFocusKeyword(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, ',')) {
            $parts = array_map(static fn (string $part): string => trim($part), explode(',', $raw));

            return $parts[0] ?? '';
        }

        return $raw;
    }
}
