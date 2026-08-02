<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 3G — sidebar keeps editor bookmark; CTA/Insert are inline at caret (no article-cta block).
 */
final class ArticleEditorSidebarCtaInlineContractTest extends TestCase
{
    private function readAddon(string $relative): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);
        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }

    public function test_sidebar_click_does_not_clear_active_editor_context(): void
    {
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');
        $ctx = $this->readAddon('resources/js/utils/editorInsertionContext.js');

        self::assertStringContainsString('isAssistantFocusStealTarget(e.target)', $editor);
        self::assertStringContainsString('never clear active editor context on click', $editor);
        self::assertStringContainsString('syncAndFreezeInsertionContext', $editor);
        self::assertStringContainsString('Do not overwrite', $ctx);
        self::assertStringContainsString('.seo-assistant-dock', $ctx);
        self::assertStringContainsString('.wp-article-edit-sidebar', $ctx);
    }

    public function test_cta_and_value_share_inline_insertion_flow(): void
    {
        $selection = $this->readAddon('resources/js/utils/editorSelectionUtils.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');

        self::assertStringContainsString('insertInlinePartsAtBookmark', $selection);
        self::assertStringContainsString('insertCtaInlineAtBookmark', $selection);
        self::assertStringContainsString('insertContactValueAtBookmark', $selection);
        self::assertStringContainsString('insertCtaInlineAtBookmark', $editor);
        self::assertStringContainsString('same inline flow', $editor);
        self::assertStringContainsString('is_cta_block: false', $cta);
        self::assertStringContainsString('is_cta_sentence: true', $cta);

        self::assertStringNotContainsString('commands.lift()', $selection);
        self::assertStringNotContainsString('commands.setParagraph()', $selection);
        self::assertStringNotContainsString("class: 'article-cta'", $selection);
        self::assertStringNotContainsString('setTextSelection(docSize)', $selection);
    }

    public function test_inline_insert_collapses_caret_after_content(): void
    {
        $selection = $this->readAddon('resources/js/utils/editorSelectionUtils.js');

        self::assertStringContainsString('chainCollapseCaretAfterInsert', $selection);
        self::assertStringContainsString('TextSelection.create', $selection);
        self::assertStringContainsString('setStoredMarks([])', $selection);
        self::assertStringContainsString('NEVER force doc end', $selection);
    }
}
