<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use App\Addons\SeoContentAi\Support\KeywordPhraseMatcher;
use App\Addons\SeoContentAi\Support\LinkSuggestionStopPhraseFilter;
use Illuminate\Support\Str;

/**
 * Bóc cụm từ thật trong HTML bài viết cho content-keyword fallback.
 * Không AI / embedding.
 */
final class ArticleLinkSuggestionContentPhraseExtractor
{
    /**
     * @param  list<string>  $excludePhrases  Phrase đã suggestion / đã link (case-insensitive)
     * @param  list<string>  $priorityPhrases Focus / secondary keywords ưu tiên nếu có trong bài
     * @return list<array{phrase: string, source: string, offset: int}>
     */
    public function extract(string $html, array $excludePhrases = [], array $priorityPhrases = []): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $maxPhrases = max(1, (int) config('seo-content-ai.link_suggestions.fallback_phrase_limit', 10));
        $minWords = max(1, (int) config('seo-content-ai.link_suggestions.fallback_min_words', 2));
        $maxWords = max($minWords, (int) config('seo-content-ai.link_suggestions.fallback_max_words', 5));
        $repeatMin = max(2, (int) config('seo-content-ai.link_suggestions.fallback_repeated_ngram_min_count', 2));

        $excludeNorm = $this->normalizeSet($excludePhrases);
        $linkedPlain = $this->extractLinkedAnchorTexts($html);
        foreach ($linkedPlain as $linked) {
            $excludeNorm[$this->normKey($linked)] = true;
        }

        $htmlWithoutLinks = $this->stripAnchorTagsKeepTextAsSpace($html);
        $candidates = [];

        foreach ($priorityPhrases as $priority) {
            $priority = $this->normalizeDisplayPhrase((string) $priority);
            if ($priority === '') {
                continue;
            }
            $wordCount = KeywordPhraseMatcher::countWords($priority);
            $allowSingle = $wordCount === 1 && mb_strlen(KeywordPhraseMatcher::normalize($priority)) >= 4;
            if ($wordCount < $minWords && ! $allowSingle) {
                continue;
            }
            if ($wordCount > $maxWords) {
                continue;
            }
            $this->pushCandidate($candidates, $priority, 'priority', $htmlWithoutLinks, $excludeNorm);
        }

        foreach ($this->extractTaggedPhrases($htmlWithoutLinks, ['h2', 'h3']) as $heading) {
            foreach ($this->phraseWindows($heading, $minWords, $maxWords) as $window) {
                $this->pushCandidate($candidates, $window, 'heading', $htmlWithoutLinks, $excludeNorm, $minWords, $maxWords);
            }
        }

        foreach ($this->extractTaggedPhrases($htmlWithoutLinks, ['strong', 'b']) as $strong) {
            foreach ($this->phraseWindows($strong, $minWords, $maxWords) as $window) {
                $this->pushCandidate($candidates, $window, 'strong', $htmlWithoutLinks, $excludeNorm, $minWords, $maxWords);
            }
        }

        $plain = $this->plainTextFromHtml($htmlWithoutLinks);
        foreach ($this->repeatedNgrams($plain, $minWords, $maxWords, $repeatMin) as $ngram) {
            $this->pushCandidate($candidates, $ngram, 'ngram', $htmlWithoutLinks, $excludeNorm, $minWords, $maxWords);
        }

        // Ưu tiên: priority > heading > strong > ngram; cụm dài hơn trước.
        usort($candidates, static function (array $a, array $b): int {
            $priority = [
                'priority' => 0,
                'heading' => 1,
                'strong' => 2,
                'ngram' => 3,
            ];
            $pa = $priority[$a['source']] ?? 9;
            $pb = $priority[$b['source']] ?? 9;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return mb_strlen($b['phrase']) <=> mb_strlen($a['phrase']);
        });

        $out = [];
        $seen = [];
        foreach ($candidates as $row) {
            $key = $this->normKey($row['phrase']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
            if (count($out) >= $maxPhrases) {
                break;
            }
        }

        return $out;
    }

    /**
     * Xác nhận phrase còn tồn tại nguyên văn (sau normalize matcher) trong content
     * và không nằm trọn trong anchor đã link.
     */
    public function findVerbatimOccurrence(string $html, string $phrase): ?array
    {
        $phrase = $this->normalizeDisplayPhrase($phrase);
        if ($phrase === '' || $this->isStopPhrase($phrase)) {
            return null;
        }

        $htmlWithoutLinks = $this->stripAnchorTagsKeepTextAsSpace($html);
        $plain = $this->plainTextFromHtml($htmlWithoutLinks);
        if ($plain === '' || ! KeywordPhraseMatcher::contains($plain, $phrase)) {
            return null;
        }

        $haystack = KeywordPhraseMatcher::normalize($plain);
        $needle = KeywordPhraseMatcher::normalize($phrase);
        $offset = mb_strpos($haystack, $needle);
        if ($offset === false) {
            return null;
        }

        return [
            'phrase' => $phrase,
            'offset' => (int) $offset,
        ];
    }

    /**
     * @param  array<string, array{phrase: string, source: string, offset: int}>  $candidates
     * @param  array<string, true>  $excludeNorm
     */
    private function pushCandidate(
        array &$candidates,
        string $phrase,
        string $source,
        string $htmlWithoutLinks,
        array &$excludeNorm,
        ?int $minWords = null,
        ?int $maxWords = null,
    ): void {
        $phrase = $this->normalizeDisplayPhrase($phrase);
        if ($phrase === '') {
            return;
        }

        if ($minWords !== null && $maxWords !== null) {
            $words = KeywordPhraseMatcher::countWords($phrase);
            if ($words < $minWords || $words > $maxWords) {
                return;
            }
        }

        if (! $this->isAcceptablePhrase($phrase)) {
            return;
        }

        $key = $this->normKey($phrase);
        if ($key === '' || isset($excludeNorm[$key])) {
            return;
        }

        $occurrence = $this->findVerbatimOccurrence($htmlWithoutLinks, $phrase);
        if ($occurrence === null) {
            return;
        }

        $excludeNorm[$key] = true;
        $candidates[] = [
            'phrase' => $phrase,
            'source' => $source,
            'offset' => (int) $occurrence['offset'],
        ];
    }

    private function isAcceptablePhrase(string $phrase): bool
    {
        if (! InternalAnchorKeywordFilter::isUsableAnchorPhrase($phrase)) {
            return false;
        }

        if ($this->isStopPhrase($phrase)) {
            return false;
        }

        if (preg_match('/^[\d\s.+()-]+$/u', $phrase) === 1) {
            return false;
        }

        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $phrase) === 1) {
            return false;
        }

        $tokens = preg_split('/\s+/u', KeywordPhraseMatcher::normalize($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }

        // Một từ quá chung (≤3 ký tự ASCII) — reject trừ khi priority đã xử lý riêng.
        if (count($tokens) === 1) {
            $ascii = $this->toAscii($tokens[0]);
            if (mb_strlen($ascii) <= 3) {
                return false;
            }
        }

        return true;
    }

    private function isStopPhrase(string $phrase): bool
    {
        return LinkSuggestionStopPhraseFilter::isStopPhrase($phrase);
    }

    /**
     * @return list<string>
     */
    private function phraseWindows(string $phrase, int $minWords, int $maxWords): array
    {
        $phrase = $this->normalizeDisplayPhrase($phrase);
        if ($phrase === '') {
            return [];
        }

        $displayTokens = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = count($displayTokens);
        if ($words >= $minWords && $words <= $maxWords) {
            return [$phrase];
        }

        if ($words < $minWords) {
            return [];
        }

        $out = [];
        for ($size = min($maxWords, $words); $size >= $minWords; $size--) {
            for ($i = 0; $i <= $words - $size; $i++) {
                $out[] = implode(' ', array_slice($displayTokens, $i, $size));
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function extractTaggedPhrases(string $html, array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $quoted = preg_quote(strtolower($tag), '/');
            if (preg_match_all('/<'.$quoted.'\b[^>]*>(.*?)<\/'.$quoted.'>/is', $html, $matches) === false) {
                continue;
            }

            foreach ($matches[1] ?? [] as $inner) {
                $text = $this->normalizeDisplayPhrase($this->plainTextFromHtml((string) $inner));
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractLinkedAnchorTexts(string $html): array
    {
        if (preg_match_all('/<a\b[^>]*>(.*?)<\/a>/is', $html, $matches) === false) {
            return [];
        }

        $out = [];
        foreach ($matches[1] ?? [] as $inner) {
            $text = $this->normalizeDisplayPhrase($this->plainTextFromHtml((string) $inner));
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    private function stripAnchorTagsKeepTextAsSpace(string $html): string
    {
        // Thay cả thẻ <a>...</a> bằng khoảng trắng — phrase bên trong không còn “free text”.
        $stripped = preg_replace('/<a\b[^>]*>.*?<\/a>/is', ' ', $html) ?? $html;

        return $stripped;
    }

    /**
     * @return list<string>
     */
    private function repeatedNgrams(string $plain, int $minWords, int $maxWords, int $repeatMin): array
    {
        $normalized = KeywordPhraseMatcher::normalize($plain);
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < $minWords) {
            return [];
        }

        $counts = [];
        $countTokens = count($tokens);
        for ($size = $maxWords; $size >= $minWords; $size--) {
            for ($i = 0; $i <= $countTokens - $size; $i++) {
                $slice = array_slice($tokens, $i, $size);
                if ($this->isStopwordOnlyTokens($slice)) {
                    continue;
                }
                $gram = implode(' ', $slice);
                $counts[$gram] = ($counts[$gram] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($counts as $gram => $count) {
            if ($count >= $repeatMin) {
                $out[] = $gram;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function isStopwordOnlyTokens(array $tokens): bool
    {
        $stop = [
            'va', 'và', 'cua', 'của', 'cho', 'voi', 'với', 'la', 'là', 'cac', 'các',
            'mot', 'một', 'the', 'and', 'or', 'to', 'in', 'on', 'of', 'for', 'a', 'an',
        ];
        foreach ($tokens as $token) {
            $ascii = $this->toAscii($token);
            if (! in_array($token, $stop, true) && ! in_array($ascii, $stop, true)) {
                return false;
            }
        }

        return true;
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function normalizeDisplayPhrase(string $phrase): string
    {
        $phrase = html_entity_decode($phrase, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $phrase = strip_tags($phrase);
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? '';

        return trim($phrase);
    }

    private function normKey(string $phrase): string
    {
        return KeywordPhraseMatcher::normalize($phrase);
    }

    /**
     * @param  list<string>  $phrases
     * @return array<string, true>
     */
    private function normalizeSet(array $phrases): array
    {
        $out = [];
        foreach ($phrases as $phrase) {
            $key = $this->normKey((string) $phrase);
            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return $out;
    }

    private function toAscii(string $text): string
    {
        $ascii = Str::ascii($text, 'vi');
        $ascii = mb_strtolower(trim($ascii), 'UTF-8');
        $ascii = preg_replace('/[^a-z0-9]+/u', '', $ascii) ?? $ascii;

        return $ascii;
    }
}
