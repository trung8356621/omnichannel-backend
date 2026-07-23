<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3 static contracts: module host + dynamic import + no eager heavy mounts.
 */
final class ArticleEditorPhase3ModuleHostTest extends TestCase
{
    public function test_article_editor_entry_does_not_static_import_heavy_modules(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/article-editor.jsx',
        );

        self::assertStringNotContainsString("from './components/ArticleLinksSidebar'", $source);
        self::assertStringNotContainsString("from './components/ArticleFaqEditor'", $source);
        self::assertStringNotContainsString("from './components/ArticleAiChatPanel'", $source);
        self::assertStringContainsString('ArticleEditorModuleHost', $source);
        self::assertStringContainsString('__seoArticleEditorNavigatedBound', $source);
        self::assertStringContainsString('__seoArticleLivewireBridgeRegistered', $source);
    }

    public function test_module_host_uses_lazy_dynamic_imports_and_abort(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/ArticleEditorModuleHost.jsx',
        );

        self::assertStringContainsString("lazy(() => import('../modules/LinksModule'))", $source);
        self::assertStringContainsString("lazy(() => import('../modules/FaqModule'))", $source);
        self::assertStringContainsString("lazy(() => import('../modules/AiChatModule'))", $source);
        self::assertStringContainsString('AbortController', $source);
        self::assertStringContainsString('isAbortError', $source);
        self::assertStringContainsString('activeModule === \'faq\'', $source);
        self::assertStringContainsString('activeModule === \'ai-chat\'', $source);
        self::assertStringContainsString('activeModule === \'links\'', $source);
    }

    public function test_seo_article_editor_lazy_imports_editor_hosted_modules(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertStringNotContainsString("import SeoScorePanel from './SeoScorePanel'", $source);
        self::assertStringNotContainsString("import ArticleImagesTab from './ArticleImagesTab'", $source);
        self::assertStringNotContainsString("import ArticleReviewsTab from './ArticleReviewsTab'", $source);
        self::assertStringContainsString("lazy(() => import('../modules/SeoModule'))", $source);
        self::assertStringContainsString("lazy(() => import('../modules/ImagesModule'))", $source);
        self::assertStringContainsString("lazy(() => import('../modules/ReviewsModule'))", $source);
        self::assertStringContainsString('activeHeavyModule', $source);
        self::assertStringNotContainsString('activatedPanels', $source);
        self::assertStringContainsString('seoPanelActive', $source);
        self::assertStringContainsString('imagesPanelActive', $source);
        self::assertStringContainsString('reviewsPanelActive', $source);
    }

    public function test_module_reexport_files_exist(): void
    {
        $base = dirname(__DIR__, 2).'/resources/js/modules';
        foreach (['LinksModule.jsx', 'FaqModule.jsx', 'AiChatModule.jsx', 'ImagesModule.jsx', 'ReviewsModule.jsx', 'SeoModule.jsx'] as $file) {
            self::assertFileExists($base.'/'.$file, $file);
        }
    }
}
