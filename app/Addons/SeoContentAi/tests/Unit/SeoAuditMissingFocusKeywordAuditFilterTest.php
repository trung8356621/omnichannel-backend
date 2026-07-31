<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\SeoArticleQualityAssessmentService;
use App\Addons\SeoContentAi\Services\SeoAuditKeywordFlagService;
use App\Addons\SeoContentAi\Services\SeoAuditScanService;
use App\Addons\SeoContentAi\Support\SeoScoringRulesRegistry;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Restore contract: missing_focus_keyword is a valid SEO Audit filter.
 * Keep UNION fix: scoring-rule selection must NOT auto-merge keyword_review.
 */
final class SeoAuditMissingFocusKeywordAuditFilterTest extends TestCase
{
    public function test_missing_focus_keyword_appears_in_audit_filter_definitions(): void
    {
        $keys = array_column(SeoScoringRulesRegistry::auditFilterDefinitions(800), 'key');

        self::assertContains(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $keys);
        self::assertTrue(SeoScoringRulesRegistry::isRuleFilterable(
            SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD,
        ));
    }

    public function test_exclude_non_audit_filter_rules_keeps_missing_focus_keyword(): void
    {
        $service = (new \ReflectionClass(SeoAuditKeywordFlagService::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(SeoAuditKeywordFlagService::class, 'excludeNonAuditFilterRules');
        $method->setAccessible(true);

        /** @var list<string> $cleaned */
        $cleaned = $method->invoke(
            $service,
            [
                SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD,
                SeoScoringRulesRegistry::KEY_H2_MISSING,
                SeoScoringRulesRegistry::KEY_FAQ_MISSING,
            ],
        );

        self::assertSame([
            SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD,
            SeoScoringRulesRegistry::KEY_H2_MISSING,
            SeoScoringRulesRegistry::KEY_FAQ_MISSING,
        ], $cleaned);
    }

    public function test_scoring_selection_does_not_union_keyword_review_ids(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/SeoAuditKeywordFlagService.php',
        );

        self::assertStringContainsString('resolveResultArticleIds', $src);
        self::assertStringContainsString('Root cause fix', $src);
        self::assertStringNotContainsString(
            'array_merge($keywordArticleIds, $ruleArticleIds)',
            $src,
        );
        self::assertStringContainsString(
            'khi user chọn rule/aggregate, KHÔNG UNION keyword_review',
            $src,
        );
    }

    public function test_build_filtered_query_wires_missing_focus_canonical_scope(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/SeoAuditScanService.php',
        );

        self::assertStringContainsString('applyMissingFocusKeywordScope', $src);
        self::assertStringContainsString('hasCanonicalFocusKeyword', $src);
        self::assertStringContainsString('seo_focus_keyword', $src);
        self::assertStringContainsString('KeywordMetaKey::MainArticleId', $src);
    }

    public function test_canonical_meta_keyword_present_is_not_missing(): void
    {
        $service = app(SeoAuditScanService::class);
        $article = $this->articleWithFocusMeta('túi canvas');

        self::assertTrue($service->hasCanonicalFocusKeyword($article));
    }

    public function test_canonical_whitespace_only_meta_is_missing(): void
    {
        $service = app(SeoAuditScanService::class);
        $article = $this->articleWithFocusMeta("  \t  ");

        // Whitespace-only meta → không đủ; fallback keyword_meta query có thể fail closed nếu không có DB seed.
        // Với meta whitespace + không có MainArticleId, hasCanonical phải false khi query trả empty.
        self::assertFalse($service->hasCanonicalFocusKeyword($article));
    }

    public function test_canonical_null_empty_meta_is_missing_without_main_keyword(): void
    {
        $service = app(SeoAuditScanService::class);
        $article = $this->articleWithFocusMeta(null);

        self::assertFalse($service->hasCanonicalFocusKeyword($article));
    }

    public function test_article_matches_missing_focus_only_when_canonical_absent(): void
    {
        $scan = app(SeoAuditScanService::class);
        $service = new SeoAuditKeywordFlagService(
            app(SeoArticleQualityAssessmentService::class),
            $scan,
        );

        $method = new ReflectionMethod(SeoAuditKeywordFlagService::class, 'articleMatchesScoringFilters');
        $method->setAccessible(true);

        $missing = $this->articleWithFocusMeta('');
        $present = $this->articleWithFocusMeta('canvas bag');

        self::assertTrue($method->invoke(
            $service,
            $missing,
            [],
            [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD],
            false,
            false,
            80,
        ));

        self::assertFalse($method->invoke(
            $service,
            $present,
            [],
            [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD],
            false,
            false,
            80,
        ));
    }

    public function test_keyword_review_flags_do_not_imply_missing_focus_match(): void
    {
        $scan = app(SeoAuditScanService::class);
        $service = new SeoAuditKeywordFlagService(
            app(SeoArticleQualityAssessmentService::class),
            $scan,
        );

        $method = new ReflectionMethod(SeoAuditKeywordFlagService::class, 'articleMatchesScoringFilters');
        $method->setAccessible(true);

        // Bài đã có keyword canonical — dù UI có thể hiện warning/danger riêng.
        $article = $this->articleWithFocusMeta('đã có keyword');

        self::assertFalse($method->invoke(
            $service,
            $article,
            [],
            [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD],
            false,
            false,
            40,
        ));
    }

    public function test_faq_rule_match_does_not_require_missing_focus(): void
    {
        $scan = app(SeoAuditScanService::class);
        $service = new SeoAuditKeywordFlagService(
            app(SeoArticleQualityAssessmentService::class),
            $scan,
        );

        $method = new ReflectionMethod(SeoAuditKeywordFlagService::class, 'articleMatchesScoringFilters');
        $method->setAccessible(true);

        $article = $this->articleWithFocusMeta('có keyword');

        self::assertTrue($method->invoke(
            $service,
            $article,
            [SeoScoringRulesRegistry::KEY_FAQ_MISSING],
            [SeoScoringRulesRegistry::KEY_FAQ_MISSING],
            false,
            false,
            70,
        ));

        self::assertFalse($method->invoke(
            $service,
            $article,
            [SeoScoringRulesRegistry::KEY_FAQ_MISSING],
            [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD],
            false,
            false,
            70,
        ));
    }

    public function test_combined_missing_focus_or_faq_matches_any(): void
    {
        $scan = app(SeoAuditScanService::class);
        $service = new SeoAuditKeywordFlagService(
            app(SeoArticleQualityAssessmentService::class),
            $scan,
        );

        $method = new ReflectionMethod(SeoAuditKeywordFlagService::class, 'articleMatchesScoringFilters');
        $method->setAccessible(true);

        $missingKw = $this->articleWithFocusMeta('');
        $hasKwWithFaq = $this->articleWithFocusMeta('kw');

        $selected = [
            SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD,
            SeoScoringRulesRegistry::KEY_FAQ_MISSING,
        ];

        self::assertTrue($method->invoke($service, $missingKw, [], $selected, false, false, 80));
        self::assertTrue($method->invoke(
            $service,
            $hasKwWithFaq,
            [SeoScoringRulesRegistry::KEY_FAQ_MISSING],
            $selected,
            false,
            false,
            80,
        ));
        self::assertFalse($method->invoke($service, $hasKwWithFaq, [], $selected, false, false, 80));
    }

    public function test_articles_optimal_does_not_strip_missing_focus_from_selection(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Pages/ArticlesOptimal.php',
        );

        self::assertStringNotContainsString(
            'KEY_MISSING_FOCUS_KEYWORD',
            $src,
        );
        self::assertStringContainsString('filterPostType', $src);
    }

    private function articleWithFocusMeta(?string $value): SeoArticle
    {
        $article = new SeoArticle;
        $article->id = 991001;
        $article->exists = true;

        $metas = new Collection;
        if ($value !== null) {
            $meta = new ArticleMeta;
            $meta->meta_key = 'seo_focus_keyword';
            $meta->meta_value = $value;
            $metas->push($meta);
        }

        $article->setRelation('articleMetas', $metas);

        return $article;
    }
}
