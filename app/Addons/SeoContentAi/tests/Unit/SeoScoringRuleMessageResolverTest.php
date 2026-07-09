<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\SeoScoringRuleMessageResolver;
use App\Addons\SeoContentAi\Support\SeoScoringRulesRegistry;
use Tests\TestCase;

final class SeoScoringRuleMessageResolverTest extends TestCase
{
    public function test_normalizes_legacy_heading_key(): void
    {
        $this->assertSame(
            SeoScoringRulesRegistry::KEY_H2_MISSING,
            SeoScoringRuleMessageResolver::normalizeViolationKey('seo.heading'),
        );
    }

    public function test_pass_suffix_is_not_a_violation(): void
    {
        $this->assertNull(SeoScoringRuleMessageResolver::normalizeViolationKey('seo.heading.pass'));
    }

    public function test_message_for_legacy_seo_key(): void
    {
        $message = SeoScoringRuleMessageResolver::messageForKey('seo.heading', 'en');

        $this->assertStringContainsString('H2', $message);
        $this->assertNotSame('seo.heading', $message);
    }

    public function test_message_for_new_rule_key(): void
    {
        $message = SeoScoringRuleMessageResolver::messageForKey(
            SeoScoringRulesRegistry::KEY_H2_MISSING,
            'en',
        );

        $this->assertStringContainsString('H2', $message);
    }
}
