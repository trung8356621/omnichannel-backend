<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 3G — sidebar keeps editor bookmark; contact UI inserts CTA only (no raw value).
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
        self::assertStringContainsString('preserveEditorContextBeforeSidebarAction', $ctx);
        self::assertStringContainsString('Do not overwrite', $ctx);
        self::assertStringContainsString('looksLikeDocEnd', $ctx);
        self::assertStringContainsString('isAssistantFocusStealTarget(related)', $editor);
    }

    public function test_contact_ui_only_exposes_cta_insert(): void
    {
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');
        $links = $this->readAddon('resources/js/components/ArticleLinksSidebar.jsx');
        $domain = $this->readAddon('resources/js/components/ArticleDomainWidgetsSidebar.jsx');

        self::assertStringContainsString('cta_widget_insert_cta', $cta);
        self::assertStringContainsString('onInsertQuickCta', $cta);
        self::assertStringNotContainsString('onInsertValue', $cta);
        self::assertStringNotContainsString('onInsertValue=', $links);
        self::assertStringNotContainsString('onInsertValue=', $domain);
        self::assertStringContainsString("effectiveMode = mode === 'value' ? 'value' : 'sentence'", $cta);
        self::assertStringContainsString("data-cta-action=\"insert_contact_value\"", $cta);
        self::assertStringContainsString("onInsertQuickCta(item, itemKey, null, 'value')", $cta);
    }

    public function test_canonical_cta_command_is_insert_contact_cta_at_bookmark(): void
    {
        $selection = $this->readAddon('resources/js/utils/editorSelectionUtils.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('insertContactCtaAtBookmark', $selection);
        self::assertStringContainsString('insertContactCtaAtBookmark', $editor);
        self::assertStringContainsString("class: 'article-cta'", $selection);
        self::assertStringContainsString('article-cta__value', $selection);
        self::assertStringNotContainsString('commands.lift()', $selection);
        self::assertStringNotContainsString('setTextSelection(docSize)', $selection);
    }
}
