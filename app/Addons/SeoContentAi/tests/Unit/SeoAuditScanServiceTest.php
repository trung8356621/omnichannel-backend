<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoAuditScanService;
use App\Addons\SeoContentAi\Support\SeoScoringRulesRegistry;
use Tests\TestCase;

final class SeoAuditScanServiceTest extends TestCase
{
    public function test_missing_focus_keyword_only_uses_fast_path(): void
    {
        $service = app(SeoAuditScanService::class);

        // Rule removed from SEO Audit filter surface — no longer a "fast path only" selection.
        $this->assertFalse($service->isMissingFocusKeywordOnly(
            [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD],
            false,
            false,
        ));
    }

    public function test_aggregate_only_does_not_require_html_analysis(): void
    {
        $service = app(SeoAuditScanService::class);

        $this->assertFalse($service->isMissingFocusKeywordOnly([], true, false));
    }

    public function test_content_length_filter_no_longer_requires_runtime_html_analysis(): void
    {
        $reflection = new \ReflectionClass(SeoAuditScanService::class);

        $this->assertFalse($reflection->hasMethod('requiresHtmlAnalysis'));
        $this->assertFalse($reflection->hasMethod('scanWithHtmlAnalysis'));
    }
}
