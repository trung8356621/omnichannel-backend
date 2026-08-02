<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3 — pure Document selectors (no React).
 */
final class DocumentSelectorsTest extends TestCase
{
    public function test_selectors_are_pure_and_cover_core_nodes(): void
    {
        $path = dirname(__DIR__, 2).'/resources/js/utils/documentSelectors.js';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        foreach ([
            'selectHeadings',
            'selectH2',
            'selectLinks',
            'selectImages',
            'selectParagraphs',
            'selectFaqPlaceholders',
            'selectCtaParagraphs',
            'selectTables',
            'selectWordCount',
        ] as $symbol) {
            self::assertStringContainsString($symbol, $source);
        }
        self::assertStringNotContainsString('from \'react\'', $source);
        self::assertStringNotContainsString('from "react"', $source);
    }
}
