<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ArticleEditorRuntimeMediaPhase6c3Test extends TestCase
{
    private function js(string $relative): string
    {
        return dirname(__DIR__, 2).'/resources/js/'.$relative;
    }

    private function bladeEditArticle(): string
    {
        return dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/edit-article.blade.php';
    }

    public function test_featured_panel_renders_from_runtime_module(): void
    {
        $mod = (string) file_get_contents($this->js('editor/modules/featured/index.js'));
        self::assertStringContainsString("host: 'editor'", $mod);
        self::assertStringContainsString('FeaturedSidebarPanel', $mod);
        self::assertStringContainsString("portalRootKey: 'featured'", $mod);
        self::assertFileExists($this->js('editor/modules/featured/FeaturedSidebarPanel.jsx'));

        $panel = (string) file_get_contents($this->js('editor/modules/featured/FeaturedSidebarPanel.jsx'));
        self::assertStringContainsString('useEditorMedia', $panel);
        self::assertStringContainsString('useEditorMediaPicker', $panel);
        self::assertStringContainsString("mode: 'featured'", $panel);
        self::assertStringNotContainsString('window.Alpine', $panel);
        self::assertStringNotContainsString('localStorage', $panel);
        self::assertStringNotContainsString('dispatchEvent', $panel);
    }

    public function test_blade_has_featured_portal_not_primary_alpine_ui(): void
    {
        $blade = (string) file_get_contents($this->bladeEditArticle());
        self::assertStringContainsString('seo-article-featured-root', $blade);
        self::assertStringContainsString('article-editor-media-picker-root', $blade);
        self::assertStringContainsString('data-editor-portal="media.picker"', $blade);
        self::assertStringNotContainsString('wp-featured-image-picker', $blade);
        self::assertStringNotContainsString('seoProductAlbumBoxData', $blade);
        self::assertStringNotContainsString('x-show="mediaModalOpen"', $blade);
        self::assertStringNotContainsString('featuredImageDraft', $blade);
        self::assertStringNotContainsString('mediaModalOpen', $blade);
        self::assertStringContainsString('__seoOpenSharedMediaPicker', $blade);
    }

    public function test_gallery_module_nav_chip_false_ui_via_featured(): void
    {
        $mod = (string) file_get_contents($this->js('editor/modules/gallery/index.js'));
        self::assertStringContainsString('navChip: false', $mod);
        self::assertStringContainsString("portalRootKey: 'featured'", $mod);
        self::assertFileExists($this->js('editor/modules/gallery/GallerySidebarPanel.jsx'));

        $panel = (string) file_get_contents($this->js('editor/modules/gallery/GallerySidebarPanel.jsx'));
        self::assertStringContainsString('reorderGallery', $panel);
        self::assertStringContainsString('stableId', $panel);
        self::assertStringContainsString("mode: 'gallery'", $panel);
        self::assertStringNotContainsString('localStorage', $panel);
        self::assertStringNotContainsString('window.dispatchEvent', $panel);
    }

    public function test_shared_media_picker_single_portal_and_modes(): void
    {
        self::assertFileExists($this->js('editor/host/SharedMediaPicker.jsx'));
        self::assertFileExists($this->js('editor/runtime/editorMediaPickerStore.js'));
        self::assertFileExists($this->js('editor/host/hooks/useEditorMediaPicker.js'));
        self::assertFileExists($this->js('editor/host/hooks/useEditorMedia.js'));

        $picker = (string) file_get_contents($this->js('editor/host/SharedMediaPicker.jsx'));
        self::assertStringContainsString('createPortal', $picker);
        self::assertStringContainsString("t('media_picker_tab_wp')", $picker);
        self::assertStringContainsString("t('media_picker_tab_article')", $picker);
        self::assertStringContainsString("t('media_picker_tab_local')", $picker);
        self::assertStringContainsString('data-media-picker-refresh="1"', $picker);
        // WP tab must not be disabled solely because article is unsynced.
        self::assertStringNotContainsString('disabled={!wordpressAvailable}', $picker);
        self::assertStringNotContainsString('getElementById', $picker);
        self::assertStringNotContainsString('querySelector', $picker);

        $i18n = (string) file_get_contents($this->js('utils/i18n.js'));
        self::assertStringContainsString('media_picker_tab_wp:', $i18n);
        self::assertStringContainsString('Gốc (WP)', $i18n);

        $store = (string) file_get_contents($this->js('editor/runtime/editorMediaPickerStore.js'));
        self::assertStringContainsString("content_image'|'featured'|'gallery'", $store);
        self::assertStringContainsString('export function openMediaPicker', $store);

        $host = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringContainsString('SharedMediaPicker', $host);
        self::assertStringContainsString('article-editor-media-picker-root', $host);
        self::assertStringContainsString('installMediaPickerCompatibilityBridge', $host);
        self::assertStringContainsString('seo-article-featured-root', $host);
    }

    public function test_content_image_uses_shared_picker_and_insert_command(): void
    {
        $block = (string) file_get_contents($this->js('components/ImageBlockEditor.jsx'));
        self::assertStringContainsString('openMediaPicker', $block);
        self::assertStringContainsString("mode: 'content_image'", $block);
        self::assertStringContainsString("executeEditorCommand('insert_image'", $block);
        self::assertStringNotContainsString('seo-open-article-media-picker', $block);

        $bridge = (string) file_get_contents($this->js('editor/runtime/mediaPickerCompatibilityBridge.js'));
        self::assertStringContainsString("executeEditorCommand('insert_image'", $bridge);
        self::assertStringContainsString('setFeaturedViaApi', $bridge);
        self::assertStringContainsString('replaceGalleryViaApi', $bridge);
    }

    public function test_media_api_service_uses_snapshot_version(): void
    {
        $snap = (string) file_get_contents($this->js('utils/articleEditorMediaSnapshot.js'));
        self::assertStringContainsString('expected_snapshot_version', $snap);
        self::assertStringContainsString('incoming < currentVersion', $snap);
        self::assertStringContainsString('subscribeMediaSnapshot', $snap);
        self::assertStringContainsString('setFeaturedViaApi', $snap);
        self::assertStringContainsString('reorderGalleryViaApi', $snap);

        $hook = (string) file_get_contents($this->js('editor/host/hooks/useEditorMedia.js'));
        self::assertStringContainsString('setFeaturedViaApi', $hook);
        self::assertStringContainsString('clearFeaturedViaApi', $hook);
        self::assertStringContainsString('replaceGalleryViaApi', $hook);
        self::assertStringContainsString('reorderGalleryViaApi', $hook);
        self::assertStringContainsString('canMutateEditor', $hook);
    }

    public function test_wp_protection_fix_slug_all_skips_wp_and_picker_does_not_rename(): void
    {
        $fixSlug = dirname(__DIR__, 2).'/Services/SeoMediaArticleSlugFixService.php';
        self::assertFileExists($fixSlug);
        $body = (string) file_get_contents($fixSlug);
        self::assertStringContainsString('wordpress_media_requires_explicit_rename', $body);
        self::assertStringContainsString('WordPress-linked media never bulk-renamed', $body);

        $picker = (string) file_get_contents($this->js('editor/host/SharedMediaPicker.jsx'));
        self::assertStringNotContainsString('rename', strtolower($picker));

        $health = (string) file_get_contents($this->js('utils/assistantWidgetHealth.js'));
        self::assertStringContainsString('isWordPressProtectedMedia', $health);
        self::assertStringContainsString('featured_slug_not_fixed', $health);
        self::assertStringContainsString('rowHasLocalPlaceholderSlug', $health);
    }

    public function test_health_providers_read_snapshot_inputs(): void
    {
        $host = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringContainsString('featuredFromSnapshot', $host);
        self::assertStringContainsString('galleryFromSnapshot', $host);
        self::assertStringContainsString('subscribeMediaSnapshot', $host);
        self::assertStringNotContainsString("seo-featured-image-updated', onFeaturedUpdated", $host);

        $galleryHealth = (string) file_get_contents($this->js('utils/assistantWidgetHealth.js'));
        self::assertStringContainsString('gallery_item_broken', $galleryHealth);
        self::assertStringContainsString("status: 'neutral'", $galleryHealth);
    }

    public function test_alpine_album_stub_not_writable_sot(): void
    {
        $entry = (string) file_get_contents($this->js('article-editor.jsx'));
        self::assertStringContainsString('no Alpine writable gallery shadow', $entry);
        self::assertStringContainsString('this.albumItems = []', $entry);
        self::assertStringNotContainsString('storage?.reorder', $entry);
    }

    public function test_editor_hosted_includes_featured_and_core_does_not_import_panel_directly(): void
    {
        $modules = (string) file_get_contents($this->js('utils/articleEditorModules.js'));
        self::assertStringContainsString("'featured'", $modules);
        self::assertStringContainsString("panel === 'featured'", $modules);

        $host = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringNotContainsString("from '../editor/modules/featured/FeaturedSidebarPanel'", $host);
        self::assertStringNotContainsString("from '../editor/modules/gallery/GallerySidebarPanel'", $host);
        self::assertStringContainsString('EditorSidebarPortalHost', $host);
    }

    public function test_stale_snapshot_guard_and_no_three_pickers(): void
    {
        $snap = (string) file_get_contents($this->js('utils/articleEditorMediaSnapshot.js'));
        self::assertStringContainsString('if (!force && current && incoming < currentVersion)', $snap);

        $jsRoot = dirname(__DIR__, 2).'/resources/js';
        $pickerFiles = glob($jsRoot.'/editor/**/*MediaPicker*') ?: [];
        self::assertNotEmpty($pickerFiles);
        $duplicateAlpineModal = (string) file_get_contents($this->bladeEditArticle());
        self::assertStringNotContainsString('class="seo-article-media-modal"', $duplicateAlpineModal);
    }
}
