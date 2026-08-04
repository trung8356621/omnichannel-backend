<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArticleEditor\Document\ArticleEditorDocumentHtmlIngest;
use App\Addons\SeoContentAi\Services\ArticleEditor\Document\ArticleEditorDocumentHtmlRenderer;
use App\Addons\SeoContentAi\Services\ArticleEditor\Document\ArticleEditorDocumentNodeRegistry;
use App\Addons\SeoContentAi\Services\ArticleEditor\Document\ArticleEditorDocumentSchema;
use App\Addons\SeoContentAi\Services\ArticleEditor\Document\ArticleEditorDocumentWriter;
use App\Addons\SeoContentAi\Services\ArticleEditorHtmlSanitizeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Inline mark boundary whitespace must survive HTML ↔ TipTap JSON round-trips.
 */
final class ArticleEditorInlineWhitespaceRoundTripRegressionTest extends TestCase
{
    private ArticleEditorDocumentHtmlIngest $ingest;

    private ArticleEditorDocumentSchema $schema;

    private ArticleEditorHtmlSanitizeService $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingest = new ArticleEditorDocumentHtmlIngest;
        $renderer = new ArticleEditorDocumentHtmlRenderer;
        $this->schema = new ArticleEditorDocumentSchema(
            new ArticleEditorDocumentNodeRegistry,
            $renderer,
        );
        $this->sanitizer = new ArticleEditorHtmlSanitizeService;
    }

    /**
     * @return list<string>
     */
    private function textNodes(array $node): array
    {
        $out = [];
        if (isset($node['text']) && is_string($node['text'])) {
            $out[] = $node['text'];
        }
        foreach (($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $out = array_merge($out, $this->textNodes($child));
            }
        }

        return $out;
    }

    private function plainFromDoc(array $doc): string
    {
        return implode('', $this->textNodes($doc));
    }

    private function roundTrip(string $html): array
    {
        $doc1 = $this->ingest->htmlToTipTapDoc($html);
        $env = [
            'schema_version' => 1,
            'type' => 'article_document',
            'blocks' => [['id' => 'b1', 'type' => 'text', 'document' => $doc1]],
        ];
        $rendered = $this->schema->renderHtml($env);
        $cleaned = $this->sanitizer->stripTransientEditorMarkup($rendered);
        $doc2 = $this->ingest->htmlToTipTapDoc($cleaned);

        return [$doc1, $cleaned, $doc2];
    }

    public function test_strong_keeps_spaces_both_sides(): void
    {
        $html = '<p>vì <strong>Mix &amp; Match túi vải không dệt</strong> đang trở thành</p>';
        [$doc1, $cleaned, $doc2] = $this->roundTrip($html);
        self::assertSame('vì Mix & Match túi vải không dệt đang trở thành', $this->plainFromDoc($doc1));
        self::assertSame($this->plainFromDoc($doc1), $this->plainFromDoc($doc2));
        self::assertStringContainsString('vì <strong>', $cleaned);
        self::assertStringContainsString('</strong> đang', $cleaned);
        $nodes = $this->textNodes($doc1);
        self::assertContains('vì ', $nodes);
        self::assertContains(' đang trở thành', $nodes);
    }

    public function test_em_and_link_keep_spaces(): void
    {
        $cases = [
            '<p>alpha <em>beta</em> gamma</p>',
            '<p>alpha <a href="https://example.com/x">beta</a> gamma</p>',
        ];
        foreach ($cases as $html) {
            [$doc1, $cleaned, $doc2] = $this->roundTrip($html);
            self::assertSame('alpha beta gamma', $this->plainFromDoc($doc1), $html);
            self::assertSame($this->plainFromDoc($doc1), $this->plainFromDoc($doc2), $html);
            self::assertStringContainsString('alpha ', $cleaned);
            self::assertMatchesRegularExpression('/<\/(?:em|a)> gamma/', $cleaned);
        }
    }

    public function test_punctuation_not_extra_spaced(): void
    {
        $html = '<p><strong>Từ khóa</strong>, ví dụ</p>';
        [$doc1, $cleaned] = $this->roundTrip($html);
        self::assertSame('Từ khóa, ví dụ', $this->plainFromDoc($doc1));
        self::assertStringContainsString('</strong>, ví dụ', $cleaned);
    }

    public function test_nested_marks_and_vietnamese(): void
    {
        $html = '<p>trước <strong><em>lồng nhau</em></strong> sau</p>';
        [$doc1] = $this->roundTrip($html);
        self::assertSame('trước lồng nhau sau', $this->plainFromDoc($doc1));
    }

    public function test_table_cell_heading_list_blockquote(): void
    {
        $html = '<h2>Tiêu đề <strong>đậm</strong> nhé</h2>'
            .'<ul><li>mục <em>nghiêng</em> đây</li></ul>'
            .'<blockquote><p>trích <a href="https://ex.test/a">link</a> xong</p></blockquote>'
            .'<table><tr><td>ô <strong>đậm</strong> cuối</td></tr></table>';
        [$doc1, $cleaned, $doc2] = $this->roundTrip($html);
        self::assertStringContainsString('Tiêu đề đậm nhé', $this->plainFromDoc($doc1));
        self::assertStringContainsString('mục nghiêng đây', $this->plainFromDoc($doc1));
        self::assertStringContainsString('trích link xong', $this->plainFromDoc($doc1));
        self::assertStringContainsString('ô đậm cuối', $this->plainFromDoc($doc1));
        self::assertSame($this->plainFromDoc($doc1), $this->plainFromDoc($doc2));
        self::assertStringContainsString('<table>', $cleaned);
        self::assertStringContainsString('<h2>', $cleaned);
    }

    public function test_bootstrap_rejects_json_that_lost_mark_spaces(): void
    {
        $writerSource = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleEditorDocumentWriter::class))->getFileName(),
        );
        self::assertStringContainsString('hasInlineWhitespaceCorruption', $writerSource);
        self::assertStringContainsString('body_html_repaired', $writerSource);
        self::assertStringContainsString('InlineMarkBoundaryWhitespace', $writerSource);

        $method = new ReflectionMethod(ArticleEditorDocumentWriter::class, 'hasInlineWhitespaceCorruption');
        $writer = (new ReflectionClass(ArticleEditorDocumentWriter::class))->newInstanceWithoutConstructor();
        self::assertTrue($method->invoke(
            $writer,
            'vì Mix & Match túi vải không dệt đang trở thành',
            'vìMix & Match túi vải không dệtđang trở thành',
        ));
        self::assertFalse($method->invoke(
            $writer,
            'vì Mix Match đang',
            'vì Mix Match đang',
        ));
        // Single intentional space delete must not trip mass-corruption.
        self::assertFalse($method->invoke(
            $writer,
            'alpha beta gamma',
            'alphabeta gamma',
            2,
        ));
    }

    public function test_glued_mark_boundary_repair_is_surgical(): void
    {
        $repair = new \App\Addons\SeoContentAi\Services\ArticleEditor\Document\InlineMarkBoundaryWhitespace;
        $broken = '<p>vì<strong>Mix &amp; Match túi vải không dệt nam</strong>đang trở thành <em>Streetwear</em>chính hiệu</p>';
        $report = $repair->repairWithReport($broken);
        self::assertTrue($report['repaired']);
        self::assertSame(0, $report['glued_after']);
        self::assertStringContainsString('vì <strong>', $report['html']);
        self::assertStringContainsString('</strong> đang', $report['html']);
        self::assertStringContainsString('</em> chính', $report['html']);

        $punct = '<p><strong>Từ khóa</strong>, ví dụ</p>';
        $punctReport = $repair->repairWithReport($punct);
        self::assertFalse($punctReport['repaired']);
        self::assertStringContainsString('</strong>, ví dụ', $punctReport['html']);
    }

    public function test_client_preserve_whitespace_and_hydration_guards_exist(): void
    {
        $guard = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/inlineWhitespaceGuard.js',
        );
        $editor = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/SeoArticleEditor.jsx',
        );
        $docJs = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/articleEditorDocument.js',
        );
        $shell = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/article-editor.jsx',
        );

        self::assertStringContainsString('repairGluedInlineMarkBoundaryWhitespace', $guard);
        self::assertStringContainsString('countGluedInlineMarkBoundaries', $guard);
        self::assertStringContainsString("preserveWhitespace: 'full'", $guard);
        self::assertStringContainsString('TIPTAP_HTML_PARSE_OPTIONS', $editor);
        self::assertStringContainsString('parseOptions: TIPTAP_HTML_PARSE_OPTIONS', $editor);
        self::assertStringContainsString('acceptUpdatesRef', $editor);
        self::assertStringContainsString('INLINE_WHITESPACE_CORRUPTION_CODE', $editor);
        self::assertStringContainsString('assertWritableDocumentNotWhitespaceCorrupted', $editor);
        self::assertStringContainsString('__seoCollectEditorHeavyBundle', $editor);
        self::assertStringContainsString('__seoAssertEditorWhitespaceSafe', $editor);
        self::assertStringContainsString('__seoAssertEditorWhitespaceSafe', $shell);
        self::assertStringContainsString('hasInlineWhitespaceCorruption', $docJs);
        self::assertStringContainsString('skipNextAutosave.current = true', $editor);
    }

    public function test_ingest_keeps_whitespace_only_text_nodes_inline(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleEditorDocumentHtmlIngest::class))->getFileName(),
        );
        self::assertStringContainsString('Keep whitespace-only text when non-empty', $source);
        self::assertStringContainsString("if (\$text === '')", $source);
        self::assertDoesNotMatchRegularExpression(
            '/DOMText[\s\S]{0,200}trim\(\$text\)\s*===\s*[\'\"]{2}/',
            $source,
        );
    }
}
