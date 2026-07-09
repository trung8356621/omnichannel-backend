<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Services\SeoScoringSettingsService;

final class SeoScoringRulesRegistry
{
    public const BASE_SCORE = 100;

    public const META_KEY_VIOLATIONS = 'seo_rule_violations';

    public const KEY_MISSING_FOCUS_KEYWORD = 'missing_focus_keyword';

    public const KEY_H2_MISSING = 'h2_missing';

    public const KEY_CONTENT_LENGTH_LOW = 'content_length_low';

    public const KEY_IMAGE_RATIO_MISSING = 'image_ratio_missing';

    public const KEY_IMAGE_RATIO_POOR = 'image_ratio_poor';

    public const KEY_IMAGE_RATIO_LOW = 'image_ratio_low';

    public const KEY_IMAGE_RATIO_SUBOPTIMAL = 'image_ratio_suboptimal';

    public const KEY_IMAGE_ALT_MISSING = 'image_alt_missing';

    public const KEY_WIKI_TRUST_MISSING = 'wiki_trust_missing';

    public const KEY_FAQ_MISSING = 'faq_missing';

    public const KEY_KEYWORD_MISSING_IN_TITLE = 'keyword_missing_in_title';

    public const KEY_KEYWORD_MISSING_IN_META = 'keyword_missing_in_meta';

    public const KEY_KEYWORD_MISSING_IN_SLUG = 'keyword_missing_in_slug';

    public const KEY_KEYWORD_MISSING_IN_INTRO = 'keyword_missing_in_intro';

    public const KEY_FEATURED_SNIPPET_MISSING = 'featured_snippet_missing';

    public const KEY_FEATURED_SNIPPET_BELOW_GOOD = 'featured_snippet_below_good';

    public const KEY_FEATURED_SNIPPET_BELOW_EXCELLENT = 'featured_snippet_below_excellent';

    /**
     * @return list<array{key: string, deduction: int, locale_key: string}>
     */
    public static function defaultRules(): array
    {
        return [
            ['key' => self::KEY_MISSING_FOCUS_KEYWORD, 'deduction' => 100, 'locale_key' => 'seo_rules.missing_focus_keyword'],
            ['key' => self::KEY_H2_MISSING, 'deduction' => 20, 'locale_key' => 'seo_rules.h2_missing'],
            ['key' => self::KEY_CONTENT_LENGTH_LOW, 'deduction' => 15, 'locale_key' => 'seo_rules.content_length_low'],
            ['key' => self::KEY_IMAGE_RATIO_MISSING, 'deduction' => 15, 'locale_key' => 'seo_rules.image_ratio_missing'],
            ['key' => self::KEY_IMAGE_RATIO_POOR, 'deduction' => 12, 'locale_key' => 'seo_rules.image_ratio_poor'],
            ['key' => self::KEY_IMAGE_RATIO_LOW, 'deduction' => 7, 'locale_key' => 'seo_rules.image_ratio_low'],
            ['key' => self::KEY_IMAGE_RATIO_SUBOPTIMAL, 'deduction' => 5, 'locale_key' => 'seo_rules.image_ratio_suboptimal'],
            ['key' => self::KEY_IMAGE_ALT_MISSING, 'deduction' => 5, 'locale_key' => 'seo_rules.image_alt_missing'],
            ['key' => self::KEY_WIKI_TRUST_MISSING, 'deduction' => 15, 'locale_key' => 'seo_rules.wiki_trust_missing'],
            ['key' => self::KEY_FAQ_MISSING, 'deduction' => 10, 'locale_key' => 'seo_rules.faq_missing'],
            ['key' => self::KEY_KEYWORD_MISSING_IN_TITLE, 'deduction' => 4, 'locale_key' => 'seo_rules.keyword_missing_in_title'],
            ['key' => self::KEY_KEYWORD_MISSING_IN_META, 'deduction' => 4, 'locale_key' => 'seo_rules.keyword_missing_in_meta'],
            ['key' => self::KEY_KEYWORD_MISSING_IN_SLUG, 'deduction' => 4, 'locale_key' => 'seo_rules.keyword_missing_in_slug'],
            ['key' => self::KEY_KEYWORD_MISSING_IN_INTRO, 'deduction' => 3, 'locale_key' => 'seo_rules.keyword_missing_in_intro'],
            ['key' => self::KEY_FEATURED_SNIPPET_MISSING, 'deduction' => 10, 'locale_key' => 'seo_rules.featured_snippet_missing'],
            ['key' => self::KEY_FEATURED_SNIPPET_BELOW_GOOD, 'deduction' => 7, 'locale_key' => 'seo_rules.featured_snippet_below_good'],
            ['key' => self::KEY_FEATURED_SNIPPET_BELOW_EXCELLENT, 'deduction' => 4, 'locale_key' => 'seo_rules.featured_snippet_below_excellent'],
        ];
    }

    /**
     * @return list<array{key: string, deduction: int, enabled: bool, locale_key: string}>
     */
    public static function rules(): array
    {
        return app(SeoScoringSettingsService::class)->effectiveRules();
    }

    /**
     * @return list<array{key: string, deduction: int, enabled: bool, locale_key: string}>
     */
    public static function publicRulesForClient(): array
    {
        return self::rules();
    }

    /**
     * @return array<string, string>
     */
    public static function messagesForLocale(?string $locale = null): array
    {
        $previous = app()->getLocale();
        if ($locale !== null && $locale !== '') {
            app()->setLocale($locale);
        }

        $lines = [];
        foreach (self::defaultRules() as $rule) {
            $langKey = str_starts_with($rule['locale_key'], 'seo_rules.')
                ? substr($rule['locale_key'], 10)
                : $rule['locale_key'];
            $lines[$rule['locale_key']] = (string) __("seo_rules.{$langKey}");
        }

        foreach (array_keys((array) trans('seo')) as $legacyKey) {
            $lines['seo.'.$legacyKey] = (string) __("seo.{$legacyKey}");
        }

        if ($locale !== null && $locale !== '') {
            app()->setLocale($previous);
        }

        return $lines;
    }

    public static function defaultDeductionFor(string $key): int
    {
        foreach (self::defaultRules() as $rule) {
            if ($rule['key'] === $key) {
                return (int) $rule['deduction'];
            }
        }

        return 0;
    }

    public static function deductionFor(string $key): int
    {
        return app(SeoScoringSettingsService::class)->deductionFor($key);
    }

    public static function isRuleEnabled(string $key): bool
    {
        return app(SeoScoringSettingsService::class)->isRuleEnabled($key);
    }

    public static function isKnownKey(string $key): bool
    {
        $normalized = SeoScoringRuleMessageResolver::normalizeViolationKey($key);

        return $normalized !== null && (
            self::defaultDeductionFor($normalized) > 0
            || $normalized === self::KEY_MISSING_FOCUS_KEYWORD
        );
    }

    /**
     * @param  list<string>  $violations
     * @return list<string>
     */
    public static function sanitizeViolations(array $violations): array
    {
        $result = [];
        foreach ($violations as $key) {
            if (! is_string($key)) {
                continue;
            }

            $normalized = SeoScoringRuleMessageResolver::normalizeViolationKey($key);
            if ($normalized === null) {
                continue;
            }

            $result[] = $normalized;
        }

        return array_values(array_unique($result));
    }

    /**
     * @return list<string>
     */
    public static function knownKeys(): array
    {
        return array_map(
            static fn (array $rule): string => $rule['key'],
            self::defaultRules(),
        );
    }
}
