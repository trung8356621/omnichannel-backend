<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\SeoScoringCalculator;

final class SeoRuleViolationsResolver
{
    /**
     * @return list<string>
     */
    public static function forArticle(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');

        $fromNew = self::readViolationsMeta($article, SeoScoringRulesRegistry::META_KEY_VIOLATIONS);
        if ($fromNew !== null) {
            return SeoScoringRulesRegistry::activeViolations($fromNew);
        }

        $legacy = self::convertLegacyRankMathScore(
            self::decodeMetaJson($article, 'seo_rank_math_score'),
        );
        $legacyBonus = self::convertLegacyScoringDetails(
            self::decodeMetaJson($article, 'seo_scoring_details'),
        );

        return SeoScoringRulesRegistry::activeViolations(
            SeoScoringRulesRegistry::sanitizeViolations(array_merge($legacy, $legacyBonus)),
        );
    }

    /**
     * @param  list<string>  $violations
     * @return list<string>
     */
    public static function activeViolationsForArticle(SeoArticle $article): array
    {
        return self::forArticle($article);
    }

    public static function scoreForArticle(SeoArticle $article): ?int
    {
        if (! $article->countsTowardSeoScore()) {
            return null;
        }

        $violations = self::forArticle($article);
        if ($violations === [] && $article->seo_score === null) {
            return null;
        }

        return SeoScoringCalculator::scoreFromViolations($violations);
    }

    /**
     * @return list<string>|null
     */
    private static function readViolationsMeta(SeoArticle $article, string $metaKey): ?array
    {
        $decoded = self::decodeMetaJson($article, $metaKey);
        if ($decoded === null) {
            return null;
        }

        if (self::isViolationList($decoded)) {
            return SeoScoringRulesRegistry::sanitizeViolations($decoded);
        }

        return null;
    }

    private static function isViolationList(mixed $decoded): bool
    {
        if (! is_array($decoded) || $decoded === []) {
            return is_array($decoded);
        }

        return array_is_list($decoded) && is_string($decoded[0] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $legacy
     * @return list<string>
     */
    private static function convertLegacyRankMathScore(?array $legacy): array
    {
        if ($legacy === null) {
            return [];
        }

        if (self::isViolationList($legacy)) {
            return SeoScoringRulesRegistry::sanitizeViolations($legacy);
        }

        $violations = [];

        $reasonKeys = is_array($legacy['reason_keys'] ?? null) ? $legacy['reason_keys'] : [];
        foreach ($reasonKeys as $key) {
            if (! is_string($key)) {
                continue;
            }

            $mapped = SeoScoringRuleMessageResolver::normalizeViolationKey($key);
            if ($mapped !== null) {
                $violations[] = $mapped;
            }
        }

        $breakdown = is_array($legacy['breakdown'] ?? null) ? $legacy['breakdown'] : [];
        foreach ($breakdown as $category) {
            if (! is_array($category) || ($category['passed'] ?? false) === true) {
                continue;
            }

            $mapped = self::mapLegacyBreakdownCategory($category);
            if ($mapped !== null) {
                $violations[] = $mapped;
            }
        }

        return SeoScoringRulesRegistry::sanitizeViolations($violations);
    }

    /**
     * @param  array<string, mixed>|null  $legacy
     * @return list<string>
     */
    private static function convertLegacyScoringDetails(?array $legacy): array
    {
        if ($legacy === null) {
            return [];
        }

        $violations = [];

        $faq = is_array($legacy['faq'] ?? null) ? $legacy['faq'] : [];
        if (($faq['passed'] ?? false) !== true) {
            $violations[] = SeoScoringRulesRegistry::KEY_FAQ_MISSING;
        }

        $table = is_array($legacy['table'] ?? null) ? $legacy['table'] : [];
        if (($table['passed'] ?? false) !== true) {
            $tier = (string) ($table['tier'] ?? 'none');
            $snippetKey = match ($tier) {
                'excellent' => null,
                'good' => SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_BELOW_EXCELLENT,
                'average' => SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_BELOW_GOOD,
                default => SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_MISSING,
            };

            if ($snippetKey !== null) {
                $violations[] = $snippetKey;
            }
        }

        return $violations;
    }

    private static function mapLegacyReasonKey(string $key): ?string
    {
        return match ($key) {
            'seo.missing_focus_keyword' => SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD,
            'seo.heading' => SeoScoringRulesRegistry::KEY_H2_MISSING,
            'seo.length' => SeoScoringRulesRegistry::KEY_CONTENT_LENGTH_LOW,
            'seo.image_ratio' => SeoScoringRulesRegistry::KEY_IMAGE_RATIO_MISSING,
            'seo.wiki_trust' => SeoScoringRulesRegistry::KEY_WIKI_TRUST_MISSING,
            'seo.faq_schema' => SeoScoringRulesRegistry::KEY_FAQ_MISSING,
            'seo.keyword_density' => SeoScoringRulesRegistry::KEY_KEYWORD_MISSING_IN_TITLE,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private static function mapLegacyBreakdownCategory(array $category): ?string
    {
        $key = (string) ($category['key'] ?? '');

        return self::mapLegacyReasonKey($key);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeMetaJson(SeoArticle $article, string $key): ?array
    {
        /** @var ArticleMeta|null $meta */
        $meta = $article->articleMetas->firstWhere('meta_key', $key);
        if ($meta === null || ! is_string($meta->meta_value) || trim($meta->meta_value) === '') {
            return null;
        }

        $decoded = json_decode($meta->meta_value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
