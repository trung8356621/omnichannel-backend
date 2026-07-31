<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoAuditKeywordFlagService;
use App\Addons\SeoContentAi\Support\SeoScoringRulesRegistry;
use ReflectionMethod;
use Tests\TestCase;

final class SeoAuditMissingFocusKeywordAuditFilterTest extends TestCase
{
    public function test_missing_focus_keyword_not_in_audit_filter_definitions(): void
    {
        $keys = array_column(SeoScoringRulesRegistry::auditFilterDefinitions(800), 'key');

        self::assertNotContains(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $keys);
        self::assertFalse(SeoScoringRulesRegistry::isRuleFilterable(
            SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD,
        ));
    }

    public function test_exclude_non_audit_filter_rules_strips_missing_focus_keyword(): void
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
            ],
        );

        self::assertSame([SeoScoringRulesRegistry::KEY_H2_MISSING], $cleaned);
    }

    public function test_scoring_selection_does_not_require_keyword_flag_union_method(): void
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
    }
}
