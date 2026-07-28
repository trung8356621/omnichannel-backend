<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GscIntelligenceUiTest extends TestCase
{
    public function test_performance_hub_blade_has_gsc_intelligence_panel(): void
    {
        $hubBlade = dirname(__DIR__, 2).'/resources/views/seo/performance-hub.blade.php';
        $partial = dirname(__DIR__, 2).'/resources/views/seo/performance-hub/partials/gsc-intelligence-panel.blade.php';
        $phpPath = dirname(__DIR__, 2).'/Filament/Pages/SeoPerformanceHub.php';

        self::assertFileExists($hubBlade);
        self::assertFileExists($partial);
        self::assertFileExists($phpPath);

        $hub = (string) file_get_contents($hubBlade);
        self::assertStringContainsString('gsc-intelligence-panel', $hub);

        $partialSource = (string) file_get_contents($partial);
        foreach (['Overview', 'Queries', 'Pages', 'Opportunities', 'Sync'] as $label) {
            self::assertStringContainsString($label, $partialSource, "Missing sub-tab: {$label}");
        }

        $php = (string) file_get_contents($phpPath);
        self::assertStringContainsString('previewGscImport', $php);
        self::assertStringContainsString('gscImportCsv', $php);

        $enLang = dirname(__DIR__, 2).'/lang/en/filament.php';
        $enSource = (string) file_get_contents($enLang);
        self::assertStringContainsString('GSC Intelligence', $enSource);
    }
}
