<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SerpIntelligenceUiTest extends TestCase
{
    public function test_keyword_workspace_blade_has_serp_intelligence_tab_and_subtabs(): void
    {
        $bladePath = dirname(__DIR__, 2).'/resources/views/filament/pages/keyword-intelligence/view-keyword-workspace.blade.php';
        $partialPath = dirname(__DIR__, 2).'/resources/views/filament/pages/keyword-intelligence/partials/serp-intelligence-tab.blade.php';

        self::assertFileExists($bladePath);

        $blade = (string) file_get_contents($bladePath);
        self::assertStringContainsString('serp_intelligence', $blade);
        self::assertStringContainsString('tab_serp_intelligence', $blade);

        $enLang = dirname(__DIR__, 2).'/lang/en/filament.php';
        self::assertFileExists($enLang);
        $enSource = (string) file_get_contents($enLang);
        self::assertStringContainsString("'tab_serp_intelligence' => 'SERP Intelligence'", $enSource);

        if (is_file($partialPath)) {
            $partial = (string) file_get_contents($partialPath);
            foreach (['Overview', 'Queries', 'Snapshots', 'Cluster Evidence', 'Content Gaps', 'Competitors', 'Operations'] as $label) {
                self::assertStringContainsString($label, $partial, "Missing sub-tab label: {$label}");
            }
        }
    }

    public function test_view_keyword_workspace_allows_serp_tab(): void
    {
        $phpPath = dirname(__DIR__, 2).'/Filament/Pages/KeywordIntelligence/ViewKeywordWorkspace.php';
        self::assertFileExists($phpPath);

        $source = (string) file_get_contents($phpPath);
        self::assertStringContainsString("'serp_intelligence'", $source);
        self::assertStringContainsString('previewSerpImport', $source);
    }
}
