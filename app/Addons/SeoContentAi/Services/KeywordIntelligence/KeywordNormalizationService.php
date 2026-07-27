<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence;

/**
 * Normalize keyword for matching — keep original display separately.
 * Không bỏ dấu tiếng Việt, không stem, không sửa chính tả.
 */
final class KeywordNormalizationService
{
    public function normalize(string $keyword): string
    {
        $value = trim($keyword);
        if ($value === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        // Strip leading/trailing punctuation only (keep meaningful chars inside)
        $value = preg_replace('/^[\s:;,.\\-_\"\'«»“”‘’]+/u', '', $value) ?? $value;
        $value = preg_replace('/[\s:;,.\\-_\"\'«»“”‘’]+$/u', '', $value) ?? $value;
        $value = trim($value);

        return mb_strtolower($value, 'UTF-8');
    }

    public function displayKeyword(string $keyword): string
    {
        $value = trim($keyword);
        if ($value === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        $value = preg_replace('/^[\s:;,.\\-_\"\'«»“”‘’]+/u', '', $value) ?? $value;
        $value = preg_replace('/[\s:;,.\\-_\"\'«»“”‘’]+$/u', '', $value) ?? $value;

        return trim($value);
    }

    /**
     * Near-duplicate heuristic — same tokens ignoring order? No: only high similarity ratio.
     * Does NOT merge different intents.
     */
    public function isNearDuplicate(string $aNormalized, string $bNormalized): bool
    {
        if ($aNormalized === '' || $bNormalized === '' || $aNormalized === $bNormalized) {
            return false;
        }

        similar_text($aNormalized, $bNormalized, $percent);
        if ($percent < 88.0) {
            return false;
        }

        $tokensA = preg_split('/\s+/u', $aNormalized) ?: [];
        $tokensB = preg_split('/\s+/u', $bNormalized) ?: [];
        if (count($tokensA) <= 1 || count($tokensB) <= 1) {
            return false;
        }

        // Block obvious different entities: "seo là gì" vs "dịch vụ seo"
        $stop = ['là', 'gì', 'the', 'a', 'an', 'of', 'for', 'to', 'và', 'cho', 'tại'];
        $coreA = array_values(array_filter($tokensA, static fn (string $t): bool => ! in_array($t, $stop, true)));
        $coreB = array_values(array_filter($tokensB, static fn (string $t): bool => ! in_array($t, $stop, true)));

        if ($coreA === [] || $coreB === []) {
            return false;
        }

        sort($coreA);
        sort($coreB);

        return $coreA === $coreB;
    }
}
