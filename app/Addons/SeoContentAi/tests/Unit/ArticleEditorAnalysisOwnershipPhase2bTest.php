<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArticleEditor\ArticleEditorAnalysisPolicyService;
use App\Addons\SeoContentAi\Support\AssistantWidgetHealthRules;
use App\Addons\SeoContentAi\Support\SeoReasonPresentation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 2B — React immediate analysis ownership + Laravel policy contracts.
 */
final class ArticleEditorAnalysisOwnershipPhase2bTest extends TestCase
{
    public function test_analysis_policy_service_exposes_thresholds_and_registry(): void
    {
        $class = new ReflectionClass(ArticleEditorAnalysisPolicyService::class);
        self::assertTrue($class->hasMethod('forArticle'));
        self::assertTrue($class->hasMethod('externalFacts'));

        $source = (string) file_get_contents((string) $class->getFileName());
        self::assertStringContainsString('minimum_words', $source);
        self::assertStringContainsString('words_per_image', $source);
        self::assertStringContainsString('minimum_valid_links', $source);
        self::assertStringContainsString('reason_aliases', $source);
        self::assertStringContainsString('TARGET_WORDS_PER_IMAGE', $source);
        self::assertStringContainsString('MIN_VALID_HTTP_LINKS', $source);
        self::assertStringContainsString("'seo.image_ratio' => 'image_ratio_missing'", $source);
        self::assertSame(200, SeoReasonPresentation::TARGET_WORDS_PER_IMAGE);
        self::assertSame(5, AssistantWidgetHealthRules::MIN_VALID_HTTP_LINKS);
    }

    public function test_bootstrap_embeds_analysis_policy_and_drops_shadow_dispatch(): void
    {
        $edit = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        self::assertStringContainsString("'analysisPolicy'", $edit);
        self::assertStringContainsString("'externalFacts'", $edit);
        self::assertStringContainsString('ArticleEditorAnalysisPolicyService', $edit);
        self::assertStringContainsString('do not dispatch shadow seo-analyze-result', $edit);
        self::assertStringNotContainsString("dispatch('seo-analyze-result'", $edit);

        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/edit-article.blade.php',
        );
        self::assertStringNotContainsString('@seo-analyze-result.window', $blade);
    }

    public function test_frontend_policy_and_analyzer_wiring(): void
    {
        $ownership = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/articleAnalysisOwnership.js',
        );
        self::assertStringContainsString('normalizeReasonCode', $ownership);
        self::assertStringContainsString('setExternalFacts', $ownership);
        self::assertStringContainsString('resolveContentImageCounts', $ownership);
        self::assertStringContainsString('applyImageCountsToMetrics', $ownership);
        self::assertStringContainsString("'seo.image_ratio': 'image_ratio_missing'", $ownership);

        $compose = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/composeArticleAnalysis.js',
        );
        self::assertStringContainsString('composeImmediateArticleAnalysis', $compose);
        self::assertStringContainsString("analysis_owner: 'react_immediate'", $compose);

        $analyzer = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/seoAnalyzer.js',
        );
        self::assertStringContainsString('analysisPolicy', $analyzer);
        self::assertStringContainsString('resolveContentImageCounts', $analyzer);
        self::assertStringContainsString("analysis_owner: 'react_immediate'", $analyzer);

        $metrics = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/seoReasonMetrics.js',
        );
        self::assertStringContainsString('wordsPerImage', $metrics);
        self::assertStringContainsString('validImageCountOverride', $metrics);

        $health = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/assistantWidgetHealth.js',
        );
        self::assertStringContainsString('minimumValidLinksFromPolicy', $health);
        self::assertStringContainsString('links_below_minimum', $health);
        self::assertStringContainsString("severity: 'info'", $health);

        $entry = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString('setAnalysisPolicy', $entry);
        self::assertStringContainsString('setExternalFacts', $entry);
        self::assertStringContainsString('__SEO_ANALYSIS_POLICY_BOOTSTRAP__', $entry);
        self::assertStringNotContainsString("Livewire.on('seo-analyze-result'", $entry);

        $editor = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('composeImmediateArticleAnalysis', $editor);
        self::assertStringContainsString('debounceMs: 250', $editor);
        self::assertStringContainsString('externalFacts', $editor);
        self::assertStringContainsString("count_source === 'media_snapshot'", $editor);
        self::assertStringContainsString('recompute local immediate analysis', $editor);

        $i18n = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/i18n.js',
        );
        self::assertStringContainsString('Refresh external facts', $i18n);
        self::assertStringContainsString('Làm mới fact máy chủ', $i18n);
    }

    public function test_image_ratio_recommendation_math_contract(): void
    {
        // 1150 words / 200 → ceil = 6 recommended; 5 valid → missing 1.
        $recommended = (int) ceil(1150 / SeoReasonPresentation::TARGET_WORDS_PER_IMAGE);
        self::assertSame(6, $recommended);
        self::assertSame(1, max(0, $recommended - 5));
        self::assertSame(0, max(0, $recommended - 6));
    }

    public function test_analysis_ownership_docs_exist(): void
    {
        $path = dirname(__DIR__, 5).'/docs/architecture/ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md';
        self::assertFileExists($path);
        $body = (string) file_get_contents($path);
        self::assertStringContainsString('composeImmediateArticleAnalysis', $body);
        self::assertStringContainsString('ArticleEditorAnalysisPolicyService', $body);
        self::assertStringContainsString('Re-analyze', $body);
    }
}