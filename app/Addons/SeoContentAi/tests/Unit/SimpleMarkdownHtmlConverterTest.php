<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\SimpleMarkdownHtmlConverter;
use PHPUnit\Framework\TestCase;

final class SimpleMarkdownHtmlConverterTest extends TestCase
{
    private function converter(): SimpleMarkdownHtmlConverter
    {
        return new SimpleMarkdownHtmlConverter;
    }

    public function test_prepare_import_extracts_meta_and_h1(): void
    {
        $markdown = <<<'MD'
**Meta Description:** Mô tả SEO cho bài viết.

H1: Tiêu đề chính

Đoạn nội dung.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Mô tả SEO cho bài viết.', $prepared['meta_description']);
        $this->assertSame('Tiêu đề chính', $prepared['h1_title']);
        $this->assertStringNotContainsString('Tiêu đề chính', $prepared['markdown']);

        $htmlResult = $this->converter()->toHtmlWithMetadata($prepared['markdown']);
        $this->assertStringNotContainsString('<h1>', $htmlResult['html']);
        $this->assertStringContainsString('Đoạn nội dung', $htmlResult['html']);
    }

    public function test_strips_first_h1_anywhere_in_markdown(): void
    {
        $markdown = <<<'MD'
Đoạn mở đầu.

H1: Tiêu đề bài viết

## H2: Phần tiếp theo
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Tiêu đề bài viết', $prepared['h1_title']);
        $this->assertStringNotContainsString('H1:', $prepared['markdown']);
    }

    public function test_skips_hash_h1_in_html_output(): void
    {
        $html = $this->converter()->toHtml("# Tiêu đề H1\n\nĐoạn nội dung.");

        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringContainsString('Đoạn nội dung', $html);
    }

    public function test_strips_h_prefix_from_headings(): void
    {
        $html = $this->converter()->toHtml(<<<'MD'
H2: Bảng so sánh chất liệu

Nội dung.
MD);

        $this->assertStringContainsString('<h2>Bảng so sánh chất liệu</h2>', $html);
        $this->assertStringNotContainsString('H2:', $html);
    }

    public function test_converts_inline_bold_and_italic(): void
    {
        $html = $this->converter()->toHtml(<<<'MD'
**Canvas** và *Trả lời bởi Mr. Nam:*
MD);

        $this->assertStringContainsString('<b>Canvas</b>', $html);
        $this->assertStringContainsString('<i>Trả lời bởi Mr. Nam:</i>', $html);
    }

    public function test_converts_markdown_table_and_horizontal_rule(): void
    {
        $html = $this->converter()->toHtml(<<<'MD'
| Chất liệu | Độ bền |
| :--- | :--- |
| **Canvas** | Cao |

---

Kết thúc.
MD);

        $this->assertStringContainsString('<table><thead><tr><th>Chất liệu</th><th>Độ bền</th></tr></thead>', $html);
        $this->assertStringContainsString('<td><b>Canvas</b></td>', $html);
        $this->assertStringContainsString('<hr>', $html);
    }
}
