<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Http\Controllers\ArticleEditorLazyPayloadController;
use App\Addons\SeoContentAi\Services\ArticleEditorLinksPayloadService;
use App\Addons\SeoContentAi\Services\ArticleInternalLinkSuggestionService;
use App\Addons\SeoContentAi\Services\ArticleLinkSuggestionContentKeywordFallback;
use App\Addons\SeoContentAi\Services\ArticleLinkSuggestionContentPhraseExtractor;
use App\Addons\SeoContentAi\Support\LinkSuggestionScoreScale;
use App\Addons\SeoContentAi\Support\LinkSuggestionStopPhraseFilter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Runtime wiring — button «Tạo gợi ý liên kết» phải resolve content đúng
 * (không chỉ articles.body) và chạy fallback khi primary < target.
 */
final class ArticleLinkSuggestionRuntimeWiringTest extends TestCase
{
    public function test_controller_accepts_posted_content_and_fallback_mode(): void
    {
        $body = $this->methodBody(ArticleEditorLazyPayloadController::class, 'linksSuggestions');

        self::assertStringContainsString('submittedEditorContent', $body);
        self::assertStringContainsString("mode === 'fallback'", $body);
        self::assertStringContainsString('withFallbackOnly', $body);
        self::assertStringContainsString('withSuggestions', $body);
        self::assertStringNotContainsString("article->body ?? ''", $body);
    }

    public function test_payload_service_resolves_scoring_content_not_raw_body(): void
    {
        $body = $this->methodBody(ArticleEditorLinksPayloadService::class, 'resolveSuggestionContent');

        self::assertStringContainsString('resolveScoringContentForArticle', $body);
        self::assertStringContainsString('$submittedContent', $body);
    }

    public function test_stop_phrase_lien_he_shared_filter(): void
    {
        self::assertTrue(LinkSuggestionStopPhraseFilter::isStopPhrase('liên hệ'));
        self::assertTrue(LinkSuggestionStopPhraseFilter::isStopPhrase('Liên Hệ'));
        self::assertFalse(LinkSuggestionStopPhraseFilter::isStopPhrase('ngăn chống sốc'));
    }

    public function test_score_scale_is_zero_to_one_hundred(): void
    {
        self::assertSame(100, LinkSuggestionScoreScale::MAX);
        self::assertSame(100, LinkSuggestionScoreScale::clamp(150));
        self::assertSame(0, LinkSuggestionScoreScale::clamp(-3));
    }

    public function test_primary_pipeline_filters_stop_phrases(): void
    {
        $body = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'collectCandidates');

        self::assertStringContainsString('LinkSuggestionStopPhraseFilter::isStopPhrase', $body);
        self::assertStringContainsString('shouldRun($primaryValidInternal)', $body);
        self::assertStringNotContainsString('outlineHeadingPhrases', $body);
        self::assertStringContainsString('[LINK_FALLBACK_DEBUG]', $body);
    }

    public function test_fallback_gate_uses_valid_primary_count(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionContentKeywordFallback::class, 'shouldRun');
        self::assertStringContainsString('$currentInternalCount < $this->targetCount()', $body);

        $collect = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'collectCandidates');
        self::assertStringContainsString('$primaryValidInternal = count($internalSuggestions)', $collect);
        self::assertStringContainsString('shouldRun($primaryValidInternal)', $collect);
    }

    public function test_extractor_pulls_strong_before_heading_from_real_html_shape(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        $html = <<<'HTML'
            <h2>Các dòng balo thời trang học đường</h2>
            <p>Sản phẩm dùng <strong>ngăn chống sốc</strong> và <strong>chống thấm nước</strong>.</p>
            <p>Phù hợp <em>thiết bị học tập</em> và <mark>máy tính xách tay</mark>.</p>
            <p>Vui lòng <a href="/contact">liên hệ</a> nếu cần.</p>
            HTML;

        $phrases = $extractor->extract($html);
        $texts = array_map(static fn (array $row): string => mb_strtolower($row['phrase']), $phrases);

        self::assertNotContains('liên hệ', $texts);
        self::assertNotContains('các dòng balo thời trang học đường', $texts);
        self::assertNotSame([], $phrases);
        self::assertContains($phrases[0]['source'], ['strong', 'mark', 'em', 'entity', 'noun_phrase']);
        self::assertTrue(
            in_array('ngăn chống sốc', $texts, true)
            || in_array('chống thấm nước', $texts, true)
            || in_array('thiết bị học tập', $texts, true)
            || in_array('máy tính xách tay', $texts, true),
            'Expected highlight phrases, got: '.implode(' | ', $texts),
        );
    }

    public function test_provider_registers_post_suggestions_route(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Providers/SeoPanelProvider.php',
        );
        self::assertStringContainsString("editor/links/suggestions", $source);
        self::assertStringContainsString('links-suggestions.post', $source);
    }

    public function test_sidebar_posts_editor_html_and_has_fallback_button(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/ArticleLinksSidebar.jsx',
        );
        self::assertStringContainsString('requestEditorDocumentHtml', $source);
        self::assertStringContainsString("method: 'POST'", $source);
        self::assertStringContainsString("mode: 'fallback'", $source);
        self::assertStringContainsString('onGenerateFallbackSuggestions', $source);
        self::assertStringContainsString('links_generate_fallback', $source);
    }

    public function test_editor_responds_to_document_html_request(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('seo-editor-document-html-request', $source);
        self::assertStringContainsString('seo-editor-document-html', $source);
    }

    /**
     * @param  class-string  $class
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = $ref->getMethod($method);
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));

        return implode("\n", array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1,
        ));
    }
}
