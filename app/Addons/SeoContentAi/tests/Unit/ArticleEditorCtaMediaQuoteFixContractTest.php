<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ArticleEditorCtaMediaQuoteFixContractTest extends TestCase
{
    private function readAddon(string $relative): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);
        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }

    public function test_cta_freezes_insertion_on_pointerdown(): void
    {
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');
        $ctx = $this->readAddon('resources/js/utils/editorInsertionContext.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('captureCtaInsertionBeforeFocusSteal', $cta);
        self::assertStringContainsString('preserveCtaFocusWithoutRefreeze', $cta);
        self::assertStringContainsString('onPointerDown={captureCtaInsertionBeforeFocusSteal}', $cta);
        self::assertStringContainsString('seo-assistant-freeze-insertion-context', $cta);
        self::assertStringContainsString('getInsertionContextForCommand', $cta);
        self::assertStringContainsString('freezeEditorInsertionContext', $ctx);
        self::assertStringContainsString('clearFrozenEditorInsertionContext', $ctx);
        self::assertStringContainsString('syncAndFreezeInsertionContext', $ctx);
        self::assertStringContainsString('Do not overwrite', $ctx);
        self::assertStringContainsString('editor.isFocused', $ctx);
        self::assertStringContainsString('Do NOT re-sync from live editors after sidebar stole focus', $editor);
        self::assertStringContainsString('clearFrozenEditorInsertionContext', $editor);
    }

    public function test_cta_insert_uses_restored_selection_then_insertContent(): void
    {
        $selection = $this->readAddon('resources/js/utils/editorSelectionUtils.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('insertContactValueAtBookmark', $selection);
        self::assertStringContainsString('insertCtaInlineAtBookmark', $selection);
        self::assertStringContainsString('insertContactValueAtBookmark', $editor);
        self::assertStringContainsString('insertCtaInlineAtBookmark', $editor);
        self::assertStringContainsString('insertInlinePartsAtBookmark', $selection);
        self::assertStringContainsString('NEVER force doc end', $selection);
        self::assertStringContainsString('editor_contact_value_inserted', $editor);
        self::assertStringContainsString('editor_cta_block_inserted', $editor);
    }

    public function test_raw_insert_does_not_delegate_to_cta_block(): void
    {
        $selection = $this->readAddon('resources/js/utils/editorSelectionUtils.js');

        // Scope to function signature+body start — avoid matching later insertCtaBlockAtBookmark.
        self::assertMatchesRegularExpression(
            '/export function insertCtaInEditor\s*\([^)]*\)\s*\{\s*return insertContactValueAtBookmark\s*\(/',
            $selection,
        );
        self::assertDoesNotMatchRegularExpression(
            '/export function insertCtaInEditor\s*\([^)]*\)\s*\{\s*return insertCtaBlockInEditor\s*\(/',
            $selection,
        );
    }

    public function test_content_image_counter_scans_inline_html_not_only_image_blocks(): void
    {
        $counter = $this->readAddon('resources/js/utils/contentImageCounter.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('collectContentImagesFromArticle', $counter);
        self::assertStringContainsString("type === 'image'", $counter);
        self::assertStringContainsString('inline-html', $counter);
        self::assertStringContainsString('collectContentImagesFromArticle(blocks)', $editor);
        self::assertStringContainsString('Never featured/gallery/supplemental library', $editor);
    }

    public function test_orphan_quote_normalizer_moves_outside_block_quotes_only(): void
    {
        $body = $this->readAddon('resources/js/utils/orphanQuoteNormalizer.js');

        self::assertStringContainsString('normalizeOrphanQuoteCharacters', $body);
        self::assertStringContainsString('Does NOT strip user quotes inside editable text', $body);
        self::assertStringContainsString('normalizeOrphanQuoteCharacters', $this->readAddon('resources/js/components/SeoArticleEditor.jsx'));
    }
}
