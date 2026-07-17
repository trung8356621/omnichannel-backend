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

    public function test_prepare_import_extracts_bold_seo_title_with_extra_colon(): void
    {
        $markdown = <<<'MD'
**SEO Title:**: Tiêu đề SEO cần lưu

Đoạn nội dung.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Tiêu đề SEO cần lưu', $prepared['h1_title']);
        $this->assertStringNotContainsString('SEO Title', $prepared['markdown']);
        $this->assertStringContainsString('Đoạn nội dung.', $prepared['markdown']);
    }

    public function test_prepare_import_extracts_multiline_meta_description(): void
    {
        $markdown = <<<'MD'
**Meta Description:**
Dịch vụ may balo trẻ em Phonak cao cấp, thiết kế chống gù.

H1: May Balo Trẻ Em PHONAK

## Section 1
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame(
            'Dịch vụ may balo trẻ em Phonak cao cấp, thiết kế chống gù.',
            $prepared['meta_description'],
        );
        $this->assertStringNotContainsString('Meta Description', $prepared['markdown']);
    }

    public function test_prepare_import_strips_horizontal_rule_after_meta_description(): void
    {
        $markdown = <<<'MD'
**Meta Description:** Mô tả SEO cho bài viết.

---

H1: Tiêu đề chính

Đoạn nội dung.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Mô tả SEO cho bài viết.', $prepared['meta_description']);
        $this->assertStringNotContainsString('---', $prepared['markdown']);

        $htmlResult = $this->converter()->toHtmlWithMetadata($prepared['markdown']);
        $this->assertStringNotContainsString('<hr>', $htmlResult['html']);
        $this->assertStringContainsString('Đoạn nội dung', $htmlResult['html']);
    }

    public function test_strip_meta_description_from_html_paragraph(): void
    {
        $html = '<p><b>Meta Description:</b> Dịch vụ may balo trẻ em Phonak cao cấp.</p><h2>Section</h2>';

        $stripped = $this->converter()->stripMetaDescriptionFromHtml($html);

        $this->assertSame('Dịch vụ may balo trẻ em Phonak cao cấp.', $stripped['meta_description']);
        $this->assertStringNotContainsString('Meta Description', $stripped['html']);
        $this->assertStringContainsString('<h2>Section</h2>', $stripped['html']);
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

    public function test_hash_star_only_line_is_not_h1(): void
    {
        $markdown = <<<'MD'
# *

# Tiêu đề thật

Đoạn nội dung.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Tiêu đề thật', $prepared['h1_title']);
        $this->assertStringContainsString('# *', $prepared['markdown']);
    }

    public function test_hash_star_prefix_strips_for_h1(): void
    {
        $prepared = $this->converter()->prepareImport("# * Tiêu đề có dấu sao\n\nNội dung.");

        $this->assertSame('Tiêu đề có dấu sao', $prepared['h1_title']);
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

    public function test_prepare_import_does_not_treat_meta_description_heading_as_h1(): void
    {
        $markdown = <<<'MD'
# Meta Description

Mô tả SEO thật.

H1: Tiêu đề bài viết
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Tiêu đề bài viết', $prepared['h1_title']);
        $this->assertSame('Mô tả SEO thật.', $prepared['meta_description']);
        $this->assertStringNotContainsString('Meta Description', $prepared['markdown']);
    }

    public function test_prepare_import_extracts_hash_meta_description_heading(): void
    {
        $markdown = <<<'MD'
# Meta Description

Mô tả SEO cho bài viết.

H1: Tiêu đề chính
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Mô tả SEO cho bài viết.', $prepared['meta_description']);
        $this->assertSame('Tiêu đề chính', $prepared['h1_title']);
    }

    public function test_prepare_import_extracts_numbered_section_headings(): void
    {
        $markdown = <<<'MD'
### 1. Meta Description
Đặt may balo quà tặng từ thiện theo yêu cầu.

### 2. SEO Title
May Balo Quà Tặng Từ Thiện Tâm Lòng Vàng: Lan Tỏa Yêu Thương Ý Nghĩa

### 3. Introduction
Hoạt động **may balo quà tặng từ thiện** mang ý nghĩa nhân văn.

### 4. Main Content
## Ý nghĩa của hoạt động may balo quà tặng từ thiện

Nội dung chính của bài.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame(
            'Đặt may balo quà tặng từ thiện theo yêu cầu.',
            $prepared['meta_description'],
        );
        $this->assertSame(
            'May Balo Quà Tặng Từ Thiện Tâm Lòng Vàng: Lan Tỏa Yêu Thương Ý Nghĩa',
            $prepared['h1_title'],
        );
        $this->assertStringNotContainsString('Meta Description', $prepared['markdown']);
        $this->assertStringNotContainsString('SEO Title', $prepared['markdown']);
        $this->assertStringNotContainsString('Introduction', $prepared['markdown']);
        $this->assertStringNotContainsString('Main Content', $prepared['markdown']);
        $this->assertStringContainsString('Hoạt động **may balo quà tặng từ thiện** mang ý nghĩa nhân văn.', $prepared['markdown']);
        $this->assertStringContainsString('## Ý nghĩa của hoạt động may balo quà tặng từ thiện', $prepared['markdown']);
        $this->assertStringContainsString('Nội dung chính của bài.', $prepared['markdown']);
    }

    public function test_prepare_import_plain_numbered_metadata_labels(): void
    {
        $markdown = <<<'MD'
1. Meta Description: Khám phá cách xếp đồ trong quân đội giúp tối ưu không gian.

2. SEO Title: Cách xếp đồ trong quân đội: Bí quyết tối ưu không gian

3. Introduction

Trong môi trường quân đội việc sắp xếp đồ đạc rất quan trọng.

4. Main Content:

## Nghệ thuật xếp đồ trong quân đội

Nội dung chính của bài.
MD;

        $prepared = $this->converter()->prepareImport($markdown);
        $html = $this->converter()->toHtml($prepared['markdown']);

        $this->assertSame(
            'Khám phá cách xếp đồ trong quân đội giúp tối ưu không gian.',
            $prepared['meta_description'],
        );
        $this->assertSame(
            'Cách xếp đồ trong quân đội: Bí quyết tối ưu không gian',
            $prepared['h1_title'],
        );
        $this->assertSame(
            'Cách xếp đồ trong quân đội: Bí quyết tối ưu không gian',
            $prepared['seo_title'],
        );
        $this->assertStringNotContainsString('Meta Description', $prepared['markdown']);
        $this->assertStringNotContainsString('SEO Title', $prepared['markdown']);
        $this->assertStringNotContainsString('Introduction', $prepared['markdown']);
        $this->assertStringNotContainsString('Main Content', $html);
        $this->assertStringContainsString('<p>Trong môi trường quân đội', $html);
        $this->assertStringContainsString('<h2>Nghệ thuật xếp đồ trong quân đội</h2>', $html);
        $this->assertStringContainsString('Nội dung chính của bài', $html);
    }

    public function test_prepare_import_keeps_numbered_list_false_positives(): void
    {
        $markdown = <<<'MD'
## Các bước thực hiện

1. Chuẩn bị balo phù hợp
2. Phân loại vật dụng
3. Cuộn quần áo theo Ranger Roll
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertNull($prepared['meta_description']);
        $this->assertStringContainsString('1. Chuẩn bị balo phù hợp', $prepared['markdown']);
        $this->assertStringContainsString('2. Phân loại vật dụng', $prepared['markdown']);
        $this->assertStringContainsString('3. Cuộn quần áo theo Ranger Roll', $prepared['markdown']);
    }

    public function test_prepare_import_bold_mixed_punctuation_and_dashes(): void
    {
        $markdown = <<<'MD'
**Meta Description**: Mô tả đậm với dấu hai chấm ngoài bold
2) **SEO Title:** Tiêu đề SEO dạng ngoặc
3 - **Introduction:**
Đoạn mở đầu giữ lại.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Mô tả đậm với dấu hai chấm ngoài bold', $prepared['meta_description']);
        $this->assertSame('Tiêu đề SEO dạng ngoặc', $prepared['h1_title']);
        $this->assertStringNotContainsString('Introduction', $prepared['markdown']);
        $this->assertStringContainsString('Đoạn mở đầu giữ lại.', $prepared['markdown']);
    }

    public function test_prepare_import_unwraps_outer_markdown_fence(): void
    {
        $markdown = <<<'MD'
```markdown
# Tiêu đề trong fence

**Meta Description:** Mô tả trong fence

Đoạn body trong fence.
```
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Tiêu đề trong fence', $prepared['h1_title']);
        $this->assertSame('Mô tả trong fence', $prepared['meta_description']);
        $this->assertStringNotContainsString('```', $prepared['markdown']);
        $this->assertStringContainsString('Đoạn body trong fence.', $prepared['markdown']);
    }

    public function test_prepare_import_preserves_existing_html_document(): void
    {
        $html = '<p>Đã là HTML sẵn.</p><h2>Section</h2>';

        $prepared = $this->converter()->prepareImport($html);

        $this->assertNull($prepared['h1_title']);
        $this->assertNull($prepared['meta_description']);
        $this->assertSame($html, $prepared['markdown']);
    }

    public function test_prepare_import_keeps_visible_vietnamese_section_headings(): void
    {
        $markdown = <<<'MD'
## Giới thiệu về kỹ thuật Ranger Roll

Đoạn 1.

## Nội dung chính của khóa huấn luyện

Đoạn 2.

## Kết luận

Đoạn 3.
MD;

        $prepared = $this->converter()->prepareImport($markdown);
        $html = $this->converter()->toHtml($prepared['markdown']);

        $this->assertStringContainsString('<h2>Giới thiệu về kỹ thuật Ranger Roll</h2>', $html);
        $this->assertStringContainsString('<h2>Nội dung chính của khóa huấn luyện</h2>', $html);
        $this->assertStringContainsString('<h2>Kết luận</h2>', $html);
    }

    public function test_prepare_import_duplicate_metadata_first_wins(): void
    {
        $markdown = <<<'MD'
1. Meta Description: Mô tả đầu tiên
2. SEO Title: Tiêu đề đầu
Meta Description: Mô tả thứ hai không ghi đè

## Section

Body.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Mô tả đầu tiên', $prepared['meta_description']);
        $this->assertSame('Tiêu đề đầu', $prepared['h1_title']);
        $this->assertStringNotContainsString('Mô tả thứ hai', $prepared['markdown']);
        $this->assertStringContainsString('Body.', $prepared['markdown']);
    }

    public function test_prepare_import_handles_vietnamese_unicode_nbsp_and_crlf(): void
    {
        $markdown = "1. Meta Description: Khám phá\u{00A0}cách xếp đồ\r\n\r\n2. SEO Title: Tiêu đề có dấu\r\n\r\n## Mục lục\r\n\r\nNội dung.";

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Khám phá cách xếp đồ', $prepared['meta_description']);
        $this->assertSame('Tiêu đề có dấu', $prepared['h1_title']);
        $this->assertStringContainsString('## Mục lục', $prepared['markdown']);
        $this->assertStringContainsString('Nội dung.', $prepared['markdown']);
    }

    public function test_prepare_import_keeps_meta_description_sentence_as_content(): void
    {
        $markdown = <<<'MD'
## SEO basics

Meta description là một yếu tố quan trọng trong SEO.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertNull($prepared['meta_description']);
        $this->assertStringContainsString(
            'Meta description là một yếu tố quan trọng trong SEO.',
            $prepared['markdown'],
        );
    }

    public function test_prepare_import_classic_hash_title_bold_meta_hr(): void
    {
        $markdown = <<<'MD'
# My article title

**Meta Description:** My description

---

## First section
Content
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('My article title', $prepared['h1_title']);
        $this->assertSame('My description', $prepared['meta_description']);
        $this->assertStringNotContainsString('My article title', $prepared['markdown']);
        $this->assertStringNotContainsString('Meta Description', $prepared['markdown']);
        $this->assertStringNotContainsString('---', $prepared['markdown']);
        $this->assertStringContainsString('## First section', $prepared['markdown']);
        $this->assertStringContainsString('Content', $prepared['markdown']);
    }

    public function test_prepare_import_separates_h1_and_seo_title_when_both_present(): void
    {
        $markdown = <<<'MD'
H1: Tiêu đề bài viết H1
2. SEO Title: Tiêu đề SEO riêng

Đoạn body.
MD;

        $prepared = $this->converter()->prepareImport($markdown);

        $this->assertSame('Tiêu đề bài viết H1', $prepared['h1_title']);
        $this->assertSame('Tiêu đề SEO riêng', $prepared['seo_title']);
        $this->assertStringNotContainsString('SEO Title', $prepared['markdown']);
        $this->assertStringContainsString('Đoạn body.', $prepared['markdown']);
    }
}
