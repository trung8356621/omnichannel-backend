<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: break TDZ cycle defaultArticleEditorRuntime ↔ modules ↔ helpers.
 */
final class ArticleEditorRuntimeCircularImportContractTest extends TestCase
{
    public function test_module_panels_do_not_import_default_runtime_singleton(): void
    {
        $featured = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/editor/modules/featured/FeaturedSidebarPanel.jsx',
        );
        $modulesHelper = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/articleEditorModules.js',
        );
        $faqAnswer = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/FaqAnswerEditor.jsx',
        );

        self::assertStringNotContainsString(
            "from '../../runtime/defaultArticleEditorRuntime'",
            $featured,
        );
        self::assertStringContainsString('useEditorHostApiOptional', $featured);
        self::assertStringContainsString('supportsProductGallery', $featured);

        self::assertStringNotContainsString(
            "from '../editor/runtime/defaultArticleEditorRuntime'",
            $modulesHelper,
        );

        // Prefer leaf import over runtime barrel (barrel pulls modules registry).
        self::assertStringContainsString(
            "from '../editor/runtime/defaultArticleEditorRuntime'",
            $faqAnswer,
        );
        self::assertSame(
            0,
            preg_match("/from ['\"]\\.\\.\\/editor\\/runtime['\"]/", $faqAnswer),
            'FaqAnswerEditor must not import runtime barrel index',
        );
    }
}
