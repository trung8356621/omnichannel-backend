<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Sticky editor header + Help modal + page-scoped topbar hide contracts.
 */
final class ArticleEditorStickyHeaderHelpTest extends TestCase
{
    private function addonPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }

    public function test_edit_article_adds_article_editor_page_body_class(): void
    {
        $source = (string) file_get_contents(
            $this->addonPath('Filament/Resources/ArticleResource/Pages/EditArticle.php'),
        );

        self::assertStringContainsString('function getExtraBodyAttributes()', $source);
        self::assertStringContainsString("'class' => 'article-editor-page'", $source);
    }

    public function test_article_edit_page_css_hides_topbar_only_for_editor_page(): void
    {
        $source = (string) file_get_contents(
            $this->addonPath('resources/css/article-edit-page.css'),
        );

        self::assertStringContainsString('body.article-editor-page .fi-topbar', $source);
        self::assertStringContainsString('display: none !important', $source);
        self::assertStringContainsString('seo-article-editor-sticky-header', $source);
    }

    public function test_edit_article_blade_has_sticky_header_and_no_shortcuts_rail_include(): void
    {
        $source = (string) file_get_contents(
            $this->addonPath('resources/views/filament/resources/article-resource/pages/edit-article.blade.php'),
        );

        self::assertStringContainsString('data-seo-sticky-editor-header', $source);
        self::assertStringContainsString('article-editor-page', $source);
        self::assertStringContainsString('data-seo-sticky-save-status', $source);
        self::assertStringContainsString('data-article-editor-runtime-marker="sticky-help-v1"', $source);
        self::assertStringContainsString('article-editor-ui-revision', $source);
        self::assertStringNotContainsString("article-editor-shortcuts-rail')", $source);
    }

    public function test_page_actions_reuse_existing_handlers_and_expose_help(): void
    {
        $source = (string) file_get_contents(
            $this->addonPath('resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'),
        );

        self::assertStringContainsString("detail: { action: 'save' }", $source);
        self::assertStringContainsString("detail: { action: 'sync' }", $source);
        self::assertStringContainsString('wire:click="toggleArticleReview"', $source);
        self::assertStringContainsString('article-editor:help-open', $source);
        self::assertStringContainsString('data-seo-page-action="help"', $source);
        self::assertStringContainsString('page_action_help_aria', $source);
        self::assertStringContainsString('seo-article-editor-help-btn', $source);
        self::assertStringContainsString("topic: 'article-editor.overview'", $source);
        self::assertStringContainsString('seo-editor-toolbar-btn--labeled', $source);
        self::assertStringContainsString('>Help</span>', $source);
        // Must not bury Help inside More panel only.
        $helpPos = strpos($source, 'data-seo-page-action="help"');
        $morePanelPos = strpos($source, 'seo-editor-page-actions__more-panel');
        self::assertNotFalse($helpPos);
        self::assertNotFalse($morePanelPos);
        self::assertGreaterThan($morePanelPos, $helpPos);
    }

    public function test_help_modal_always_exposes_dom_marker(): void
    {
        $modal = (string) file_get_contents(
            $this->addonPath('resources/js/components/ArticleEditorHelpModal.jsx'),
        );

        self::assertStringContainsString('data-article-editor-help-modal', $modal);
        self::assertStringContainsString('data-article-editor-help-modal-host', $modal);
        self::assertStringContainsString('ARTICLE_EDITOR_HELP_OPEN_EVENT', $modal);
        self::assertStringContainsString("event.key === 'Escape'", $modal);
    }

    public function test_help_registry_and_modal_contracts(): void
    {
        $topics = (string) file_get_contents(
            $this->addonPath('resources/js/help/articleEditorHelpTopics.js'),
        );
        $modal = (string) file_get_contents(
            $this->addonPath('resources/js/components/ArticleEditorHelpModal.jsx'),
        );
        $entry = (string) file_get_contents(
            $this->addonPath('resources/js/article-editor.jsx'),
        );

        self::assertStringContainsString('ARTICLE_EDITOR_HELP_TOPICS', $topics);
        self::assertStringContainsString('article-editor.overview', $topics);
        self::assertStringContainsString('article-editor.outline', $topics);
        self::assertStringContainsString('ARTICLE_EDITOR_HELP_OPEN_EVENT', $topics);

        self::assertStringContainsString('role="dialog"', $modal);
        self::assertStringContainsString('aria-modal="true"', $modal);
        self::assertStringContainsString('aria-expanded', $modal);
        self::assertStringContainsString('HelpTopicVideo', $modal);
        self::assertStringContainsString("iframe.src = 'about:blank'", $modal);
        self::assertStringContainsString('createPortal', $modal);

        self::assertStringContainsString('ArticleEditorHelpModal', $entry);
        self::assertStringContainsString('installArticleEditorStickyHeaderBridge', $entry);
    }

    public function test_shortcuts_mount_helpers_removed_but_shortcut_logic_file_remains(): void
    {
        $headerActions = (string) file_get_contents(
            $this->addonPath('resources/js/utils/articleEditorHeaderActions.js'),
        );
        $editor = (string) file_get_contents(
            $this->addonPath('resources/js/components/SeoArticleEditor.jsx'),
        );
        $shortcuts = (string) file_get_contents(
            $this->addonPath('resources/js/utils/articleEditorShortcuts.js'),
        );

        self::assertStringNotContainsString('mountShortcutsBelowOutline', $headerActions);
        self::assertStringNotContainsString('observeShortcutsBelowOutline', $headerActions);
        self::assertStringNotContainsString('data-seo-outline-shortcuts-host', $editor);
        self::assertStringContainsString('articleShortcutActionFromEvent', $shortcuts);
        self::assertStringContainsString("return event.shiftKey ? 'sync' : 'save'", $shortcuts);
    }

    public function test_save_status_dispatched_to_sticky_header(): void
    {
        $editor = (string) file_get_contents(
            $this->addonPath('resources/js/components/SeoArticleEditor.jsx'),
        );
        $bridge = (string) file_get_contents(
            $this->addonPath('resources/js/utils/articleEditorStickyHeader.js'),
        );

        self::assertStringContainsString('article-editor:save-status', $editor);
        self::assertStringContainsString('ARTICLE_EDITOR_SAVE_STATUS_EVENT', $bridge);
        self::assertStringContainsString('data-seo-sticky-save-status', $bridge);
    }
}
