<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: articleLinkScroll ↔ articlePlainTextRange must not circular-import
 * (Vite rewrites export function → const → TDZ "Cannot access before initialization").
 */
final class ArticleEditorLinkScrollCycleContractTest extends TestCase
{
    public function test_plain_text_range_does_not_import_link_scroll(): void
    {
        $plain = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/articlePlainTextRange.js',
        );
        $scroll = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/articleLinkScroll.js',
        );
        $normalize = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/articleLinkTextNormalize.js',
        );

        self::assertStringContainsString("from './articleLinkTextNormalize'", $plain);
        self::assertStringNotContainsString("from './articleLinkScroll'", $plain);
        self::assertStringContainsString('export function normalizeLinkText', $normalize);
        self::assertStringContainsString("from './articleLinkTextNormalize'", $scroll);
        self::assertStringContainsString("from './articlePlainTextRange'", $scroll);
    }
}
