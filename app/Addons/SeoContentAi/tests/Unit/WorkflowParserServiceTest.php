<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoOverviewSettingsService;
use App\Addons\SeoContentAi\Services\SeoPromptSettingsService;
use App\Addons\SeoContentAi\Services\WorkflowParserService;
use PHPUnit\Framework\TestCase;

final class WorkflowParserServiceTest extends TestCase
{
    private function parser(): WorkflowParserService
    {
        return new WorkflowParserService(
            SeoPromptSettingsService::withDefaults(),
            SeoOverviewSettingsService::withDefaults(),
        );
    }

    public function test_parse_outline_builds_h2_h3_tree(): void
    {
        $parser = $this->parser();

        $markdown = <<<'MD'
## Giới thiệu
### Lý do chọn xưởng
## Kết luận
MD;

        $result = $parser->parseOutline($markdown);

        $this->assertCount(2, $result);
        $this->assertSame('Giới thiệu', $result[0]['text']);
        $this->assertCount(1, $result[0]['children']);
        $this->assertSame('Lý do chọn xưởng', $result[0]['children'][0]['text']);
    }

    public function test_parse_keywords_groups_by_heading(): void
    {
        $parser = $this->parser();

        $markdown = <<<'MD'
### Synonyms
- xưởng may
- nhà máy sản xuất
### LSI
- giá tận gốc
MD;

        $result = $parser->parseKeywords($markdown);

        $this->assertSame(['xưởng may', 'nhà máy sản xuất'], $result['Synonyms']);
        $this->assertSame(['giá tận gốc'], $result['LSI']);
    }

    public function test_calculate_seo_score_faq_and_table(): void
    {
        $parser = $this->parser();

        $tableRows = "| H1 | H2 |\n| --- | --- |\n";
        for ($i = 1; $i <= 10; $i++) {
            $tableRows .= "| a{$i} | b{$i} |\n";
        }

        $markdown = "## Intro\n\n## Câu hỏi thường gặp\n\n### Câu hỏi 1?\nTrả lời 1.\n\n" . $tableRows;

        $faqs = $parser->parseFaqs($markdown);
        $score = $parser->calculateSeoScore($markdown, $faqs);

        $this->assertCount(1, $faqs);
        $this->assertTrue($parser->hasFeaturedSnippetTable($markdown));
        $this->assertSame(20, $score['total_score']);
        $this->assertTrue($score['checklist']['faq']['passed']);
        $this->assertTrue($score['checklist']['table']['passed']);
    }

    public function test_calculate_seo_score_from_html_content(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>FAQ</h2>
<p><strong>Câu hỏi 1?</strong></p>
<p>Trả lời 1.</p>
<table><thead><tr><th>H1</th><th>H2</th></tr></thead><tbody>
HTML;

        for ($i = 1; $i <= 10; $i++) {
            $html .= "<tr><td>a{$i}</td><td>b{$i}</td></tr>\n";
        }
        $html .= '</tbody></table>';

        $score = $parser->calculateSeoScoreFromContent($html);

        $this->assertTrue($parser->hasFeaturedSnippetTableFromHtml($html));
        $this->assertSame(20, $score['total_score']);
        $this->assertTrue($score['checklist']['faq']['passed']);
        $this->assertTrue($score['checklist']['table']['passed']);
    }

    public function test_parse_faqs_with_label_style_headings(): void
    {
        $parser = $this->parser();

        $markdown = <<<'MD'
H2: Giới thiệu
Nội dung chính.

H2: Câu hỏi thường gặp
H3: Giá bao nhiêu?
Trả lời giá.

H3: Có bảo hành không?
Có bảo hành 12 tháng.
MD;

        $faqs = $parser->parseFaqs($markdown);
        $result = $parser->removeFaqAndAppendShortcode($markdown);

        $this->assertCount(2, $faqs);
        $this->assertSame('Giá bao nhiêu?', $faqs[0]['question']);
        $this->assertStringContainsString('[omi_faq]', $result);
        $this->assertStringNotContainsString('Trả lời giá', $result);
    }

    public function test_parse_faqs_from_markdown_bullet_list(): void
    {
        $parser = $this->parser();

        $markdown = <<<'MD'
## H2: Câu hỏi thường gặp (FAQ)

* **Số lượng đặt may tối thiểu (MOQ) là bao nhiêu?**
* *Trả lời bởi Mr. Nam:* Chúng tôi nhận đơn hàng từ 100 sản phẩm trở lên.
* **Thời gian hoàn thiện đơn hàng mẫu là bao lâu?**
* *Trả lời bởi Mr. Nam:* Quy trình lên mẫu thường mất từ 3-5 ngày.
MD;

        $faqs = $parser->parseFaqs($markdown);
        $stripped = $parser->removeFaqAndAppendShortcodeFromContent($markdown);

        $this->assertCount(2, $faqs);
        $this->assertSame('Số lượng đặt may tối thiểu (MOQ) là bao nhiêu?', $faqs[0]['question']);
        $this->assertStringContainsString('100 sản phẩm', $faqs[0]['answer']);
        $this->assertStringContainsString('[omi_faq]', $stripped);
        $this->assertStringNotContainsString('MOQ', $stripped);
    }

    public function test_remove_faq_and_append_shortcode(): void
    {
        $parser = $this->parser();

        $markdown = <<<'MD'
## Giới thiệu
Nội dung chính.

## Câu hỏi thường gặp
### Giá bao nhiêu?
Trả lời giá.

### Có bảo hành không?
Có bảo hành 12 tháng.
MD;

        $result = $parser->removeFaqAndAppendShortcode($markdown);

        $this->assertStringContainsString('## Giới thiệu', $result);
        $this->assertStringContainsString('## Câu hỏi thường gặp', $result);
        $this->assertStringNotContainsString('Giá bao nhiêu', $result);
        $this->assertStringContainsString('[omi_faq]', $result);
        $this->assertSame(2, count($parser->parseFaqs($markdown)));
    }

    public function test_strip_faq_content_keep_heading_html(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>5. Câu hỏi thường gặp</h2>
<p>Giới thiệu ngắn.</p>
<h3>❓ Câu hỏi 1: Giá bao nhiêu?</h3>
<p>Trả lời về giá.</p>
HTML;

        $stripped = $parser->stripFaqContentKeepHeadingHtml($html, treatAllAsFaqSection: true);

        $this->assertStringContainsString('<h2>', $stripped);
        $this->assertStringContainsString('[omi_faq]', $stripped);
        $this->assertStringContainsString('omi-faq-placeholder', $stripped);
        $this->assertStringNotContainsString('Giá bao nhiêu', $stripped);
        $this->assertStringNotContainsString('Trả lời về giá', $stripped);
    }

    public function test_parse_faqs_from_strong_paragraph_pairs(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>Câu Hỏi Thường Gặp (FAQ)</h2>
<p><strong>Túi vải chịu được trọng lượng bao nhiêu?</strong></p>
<p>Tùy vào định lượng vải (GSM) và cách may, thông thường từ 5–15 kg.</p>
<p><strong>Hợp Phát có nhận in logo số lượng ít không?</strong></p>
<p>Có. Chúng tôi nhận đơn hàng từ 100 chiếc trở lên với in lụa hoặc in nhiệt.</p>
HTML;

        $faqs = $parser->parseFaqsFromHtml($html);

        $this->assertCount(2, $faqs);
        $this->assertStringContainsString('trọng lượng bao nhiêu', $faqs[0]['question']);
        $this->assertStringContainsString('GSM', $faqs[0]['answer']);
        $this->assertStringContainsString('in logo', $faqs[1]['question']);
        $this->assertStringContainsString('100 chiếc', $faqs[1]['answer']);
    }

    public function test_parse_faqs_strong_pairs_without_faq_keywords_in_items(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>FAQ</h2>
<p><strong>Thời gian giao hàng?</strong></p>
<p>7–14 ngày làm việc.</p>
HTML;

        $faqs = $parser->parseFaqsFromHtml($html);

        $this->assertCount(1, $faqs);
        $this->assertSame('Thời gian giao hàng?', $faqs[0]['question']);
    }

    public function test_parse_faqs_three_items_when_answer_mentions_cau_hoi(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>5. Chuyên Gia Xưởng Giải Đáp: Những Câu Hỏi Thực Tế</h2>
<p><strong>❓ Câu hỏi 1: Logo gradient?</strong></p>
<blockquote><p><em>Trả lời 1.</em></p></blockquote>
<p><strong>❓ Câu hỏi 2: Túi in lụa có bị bay màu không?</strong></p>
<blockquote><p><em>Câu hỏi này liên quan trực tiếp đến chất lượng mực và quy trình sấy sau in.</em></p></blockquote>
<p><strong>❓ Câu hỏi 3: Chuẩn bị file thiết kế?</strong></p>
<blockquote><p><em>Trả lời 3.</em></p></blockquote>
HTML;

        $faqs = $parser->parseFaqsFromHtml($html);

        $this->assertCount(3, $faqs);
        $this->assertStringContainsString('bay màu', $faqs[1]['question']);
        $this->assertStringContainsString('Câu hỏi này', $faqs[1]['answer']);
    }

    public function test_parse_faqs_chuyen_gia_giai_dap_with_numbered_strong_questions(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>5. ❓ Chuyên Gia Xưởng Giải Đáp: Những Câu Hỏi Thực Tế Nhất Về In Ấn Túi Vải Không Dệt</h2>
<p>📞 <strong>Câu hỏi của bạn chưa có trong FAQ? Gọi ngay hotline!</strong></p>
<p><strong>❓ Câu hỏi 1: Logo công ty có 4 màu gradient — có giải pháp không?</strong></p>
<blockquote><p><em>Trả lời về in Pet chuyển nhiệt lẩy logo.</em></p></blockquote>
<p><strong>❓ Câu hỏi 2: Túi in lụa có bị bay màu không?</strong></p>
<blockquote><p><em>Trả lời về mực kháng nước và sấy nhiệt.</em></p></blockquote>
HTML;

        $faqs = $parser->parseFaqsFromHtml($html);

        $this->assertCount(2, $faqs);
        $this->assertStringContainsString('gradient', $faqs[0]['question']);
        $this->assertStringContainsString('Pet', $faqs[0]['answer']);
        $this->assertStringContainsString('bay màu', $faqs[1]['question']);
        $this->assertStringNotContainsString('Câu hỏi 1:', $faqs[0]['question']);
    }

    public function test_strip_faq_uppercase_heading_matches_lowercase_setting_keywords(): void
    {
        $parser = new WorkflowParserService(
            SeoPromptSettingsService::withDefaults(),
            SeoOverviewSettingsService::withFaqCatchKeywords(['giải đáp']),
        );

        $html = <<<'HTML'
<h2>CHUYÊN GIA TƯ VẤN GIẢI ĐÁP</h2>
<p><strong>❓ Câu hỏi 1: Giá bao nhiêu?</strong></p>
<p>Trả lời về giá.</p>
HTML;

        $stripped = $parser->stripFaqContentKeepHeadingHtml($html, false);

        $this->assertStringContainsString('<h2>', $stripped);
        $this->assertStringContainsString('GI', $stripped);
        $this->assertStringContainsString('[omi_faq]', $stripped);
        $this->assertStringNotContainsString('Trả lời về giá', $stripped);
    }

    public function test_remove_faq_from_content_for_sync_uppercase_markdown_heading(): void
    {
        $parser = new WorkflowParserService(
            SeoPromptSettingsService::withDefaults(),
            SeoOverviewSettingsService::withFaqCatchKeywords(['câu hỏi thường gặp']),
        );

        $html = <<<'HTML'
<p>Đoạn mở đầu.</p>
<h2>CÂU HỎI THƯỜNG GẶP (FAQ)</h2>
<p><strong>Có giao hàng không?</strong></p>
<p>Có, toàn quốc.</p>
HTML;

        $result = $parser->removeFaqAndAppendShortcodeFromContent($html);

        $this->assertStringContainsString('[omi_faq]', $result);
        $this->assertStringNotContainsString('toàn quốc', $result);
        $this->assertMatchesRegularExpression('/<h2>.*TH.*NG G.*P.*FAQ.*<\/h2>/iu', $result);
    }

    public function test_parse_faqs_from_html_manual_selection(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>5. Chuyên gia giải đáp: Câu hỏi thường gặp nhất</h2>
<p>Giới thiệu ngắn.</p>
<h3>❓ Câu hỏi 1: Giá bao nhiêu?</h3>
<p>Trả lời về giá.</p>
<h3>❓ Câu hỏi 2: Có bảo hành không?</h3>
<p>Có bảo hành 12 tháng.</p>
HTML;

        $faqs = $parser->parseFaqsFromHtml($html, treatAllAsFaqSection: true);

        $this->assertCount(2, $faqs);
        $this->assertStringContainsString('Giá bao nhiêu', $faqs[0]['question']);
        $this->assertStringContainsString('Trả lời về giá', $faqs[0]['answer']);
    }

    public function test_find_faq_section_heading_in_article_when_missing_from_selection(): void
    {
        $parser = $this->parser();

        $fragment = <<<'HTML'
<p><strong>❓ Câu hỏi 1: Logo có bền màu không?</strong></p>
HTML;

        $article = <<<'HTML'
<h2>5. Chuyên gia giải đáp: Câu hỏi thường gặp</h2>
<p><strong>❓ Câu hỏi 1: Logo có bền màu không?</strong></p>
<p>Trả lời ngắn.</p>
HTML;

        $heading = $parser->findFaqSectionHeadingInContent($fragment, $article);

        $this->assertNotNull($heading);
        $this->assertSame('article', $heading['source']);
        $this->assertStringContainsString('Câu hỏi thường gặp', $heading['text']);
    }

    public function test_diagnose_manual_faq_extract_lists_question_without_answer(): void
    {
        $parser = $this->parser();

        $fragment = <<<'HTML'
<p><strong>❓ Câu hỏi 1: Chỉ có câu hỏi?</strong></p>
HTML;

        $diagnosis = $parser->diagnoseManualFaqExtract($fragment);

        $this->assertSame(0, $diagnosis['valid_pairs']);
        $this->assertStringContainsString('Chỉ có câu hỏi', (string) ($diagnosis['question_candidates'][0] ?? ''));
    }

    public function test_parse_faqs_from_html_blockquote_answers_three_questions(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>5. Chuyên gia giải đáp: Câu hỏi thường gặp</h2>
<p><strong>❓ Câu hỏi 1: Logo gradient có giải pháp không?</strong></p>
<blockquote><p><em>Trả lời một trong blockquote.</em></p><p><em>Đoạn hai blockquote.</em></p></blockquote>
<p><strong>❓ Câu hỏi 2: Túi in lụa có bay màu không?</strong></p>
<blockquote><p><em>Câu hỏi này liên quan mực in.</em></p><p><em>Chi tiết thêm về sấy nhiệt.</em></p></blockquote>
<p><strong>❓ Câu hỏi 3: Chuẩn bị file logo thế nào?</strong></p>
<blockquote><p><em>File vector AI hoặc EPS.</em></p></blockquote>
HTML;

        $faqs = $parser->parseFaqsFromContent($html);

        $this->assertCount(3, $faqs);
        $this->assertStringContainsString('bay màu', $faqs[1]['question']);
        $this->assertStringContainsString('sấy nhiệt', $faqs[1]['answer']);
        $this->assertStringContainsString('vector', $faqs[2]['answer']);
    }

    public function test_preprocess_removes_omi_faq_container_before_parse(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>FAQ</h2>
<p><strong>❓ Câu hỏi 1: A?</strong></p>
<p>Trả lời A.</p>
<div class="omi-faq-container"><details><summary>Q duplicate</summary><div>A dup</div></details></div>
HTML;

        $faqs = $parser->parseFaqsFromContent($html);

        $this->assertCount(1, $faqs);
    }

    public function test_parse_faqs_from_html_manual_fragment_without_section_title(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<div>
<p><strong>❓ Câu hỏi 1: Bí mật bền màu của logo trên túi vải không dệt là gì?</strong></p>
<p><strong>💬 Anh Hoàng trả lời:</strong> Woven PP dệt chặt hơn nên chịu tải tốt. Non-woven PP ép nhiệt nên bề mặt mịn, in logo sắc nét.</p>
</div>
HTML;

        $faqs = $parser->parseFaqsFromHtml($html, treatAllAsFaqSection: true);

        $this->assertCount(1, $faqs);
        $this->assertStringContainsString('bền màu', $faqs[0]['question']);
        $this->assertStringContainsString('Woven PP', $faqs[0]['answer']);
        $this->assertStringNotContainsString('Anh Hoàng trả lời', $faqs[0]['answer']);
    }

    public function test_faq_catch_keywords_are_case_insensitive(): void
    {
        $parser = new WorkflowParserService(
            SeoPromptSettingsService::withDefaults(),
            SeoOverviewSettingsService::withFaqCatchKeywords(['FAQ', 'Hỏi Đáp']),
        );

        $markdown = <<<'MD'
## CHUYÊN GIA TƯ VẤN — HỎI ĐÁP THỰC TẾ

**Câu hỏi 1: Giá bao nhiêu?**
Trả lời về giá.
MD;

        $faqs = $parser->parseFaqsFromContent($markdown);

        $this->assertCount(1, $faqs);
        $this->assertStringContainsString('Giá bao nhiêu', $faqs[0]['question']);
    }

    public function test_parse_faqs_does_not_treat_numbered_outline_bullets_as_faq(): void
    {
        $parser = $this->parser();

        $markdown = <<<'MD'
## Giới thiệu

* **1. Khả năng tùy biến linh hoạt theo thương hiệu**
Tự do lựa chọn kiểu dáng túi tote.

* **2. Chất liệu bền bỉ và tính ứng dụng cao**
Chống thấm, chịu lực tốt.
MD;

        $faqs = $parser->parseFaqsFromContent($markdown);

        $this->assertSame([], $faqs);
    }

    public function test_parse_faqs_matches_settings_keywords_with_number_and_emoji_heading(): void
    {
        $parser = new WorkflowParserService(
            SeoPromptSettingsService::withDefaults(),
            SeoOverviewSettingsService::withFaqCatchKeywords([
                'faq',
                'câu hỏi thường gặp',
                'hỏi đáp',
                'giải đáp',
            ]),
        );

        $html = <<<'HTML'
<h2>5. ❓ Chuyên Gia Xưởng Giải Đáp: Những Câu Hỏi Thực Tế Nhất Về In Ấn Túi Vải Không Dệt</h2>
<p><strong>❓ Câu hỏi 1: Có bền màu không?</strong></p>
<p>Có, nếu sấy đúng nhiệt.</p>
HTML;

        $faqs = $parser->parseFaqsFromHtml($html);

        $this->assertCount(1, $faqs);
        $this->assertStringContainsString('bền màu', $faqs[0]['question']);
    }

    public function test_parse_faqs_from_html_skips_non_answer_paragraph_between_question_and_answer(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>5. ❓ Chuyên Gia Xưởng Giải Đáp: Những Câu Hỏi Thực Tế</h2>
<p><strong>❓ Câu hỏi 1: Logo có bền màu không?</strong></p>
<p><img src="/media/demo.jpg" alt="" /></p>
<p>Đây là đoạn không liên quan, chỉ để minh họa.</p>
<blockquote><p><em>Mực tốt + sấy đúng nhiệt giúp logo bền màu.</em></p></blockquote>
<p><strong>❓ Câu hỏi 2: Có hỗ trợ số lượng ít không?</strong></p>
<blockquote><p><em>Có, tùy công nghệ in.</em></p></blockquote>
HTML;

        $faqs = $parser->parseFaqsFromHtml($html);

        $this->assertCount(2, $faqs);
        $this->assertStringContainsString('bền màu', $faqs[0]['question']);
        $this->assertStringContainsString('sấy đúng nhiệt', $faqs[0]['answer']);
        $more = (string) ($faqs[0]['more'] ?? '');
        $this->assertStringContainsString('không liên quan', $more);
        $this->assertStringContainsString('<img', $more);
    }

    public function test_strip_faq_keeps_intro_between_title_and_first_question(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<h2>5. Câu hỏi thường gặp</h2>
<p>Đoạn mô tả mở đầu của FAQ.</p>
<p><strong>❓ Câu hỏi 1: A?</strong></p>
<p>Trả lời A.</p>
HTML;

        $stripped = $parser->stripFaqContentKeepHeadingHtml($html, false);

        $this->assertStringContainsString('FAQ.</p>', $stripped);
        $this->assertStringContainsString('[omi_faq]', $stripped);
        $this->assertStringNotContainsString('Trả lời A.', $stripped);
    }

    public function test_parse_faqs_b_tag_blockquote_manual_fragment(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<p><b>Q1: Chất liệu vải xưởng dùng may balo trẻ em có đảm bảo an toàn cho sức khỏe của bé không?</b></p>
<blockquote><p>An toàn của trẻ là ưu tiên số một của chúng tôi. Toàn bộ nguồn vải dù, <a href="https://example.com">vải Oxford</a> hay Canvas được xưởng sử dụng đều trải qua kiểm định, cam kết không chứa formaldehyde và các hóa chất tồn dư độc hại.</p></blockquote>
HTML;

        $faqs = $parser->parseFaqsFromHtml($html, treatAllAsFaqSection: true);

        $this->assertCount(1, $faqs);
        $this->assertStringContainsString('an toàn cho sức khỏe', mb_strtolower($faqs[0]['question']));
        $this->assertStringContainsString('formaldehyde', mb_strtolower($faqs[0]['answer']));
        $this->assertStringContainsString('vải Oxford', $faqs[0]['answer']);
    }

    public function test_is_likely_non_faq_question_skips_xem_them(): void
    {
        $parser = $this->parser();

        $this->assertTrue($parser->isLikelyNonFaqQuestion('Xem thêm:'));
        $this->assertTrue($parser->isLikelyNonFaqQuestion('See more'));

        $html = <<<'HTML'
<h2>Câu hỏi thường gặp</h2>
<h3>Câu hỏi thật?</h3>
<p>Trả lời thật.</p>
<h3>Xem thêm:</h3>
<ul><li><a href="/a">Link A</a></li></ul>
HTML;

        $faqs = $parser->parseFaqsFromHtml($html, treatAllAsFaqSection: false);

        $this->assertCount(1, $faqs);
        $this->assertStringContainsString('Câu hỏi thật', $faqs[0]['question']);
    }

    public function test_featured_snippet_tiered_scoring(): void
    {
        $parser = $this->parser();

        $buildTable = static function (int $dataRows): string {
            $rows = "| H1 | H2 |\n| --- | --- |\n";
            for ($i = 1; $i <= $dataRows; $i++) {
                $rows .= "| a{$i} | b{$i} |\n";
            }

            return $rows;
        };

        $none = $parser->resolveFeaturedSnippetTableScore($buildTable(4));
        $this->assertSame(0, $none['points']);
        $this->assertSame('none', $none['tier']);

        $average = $parser->resolveFeaturedSnippetTableScore($buildTable(6));
        $this->assertSame(3, $average['points']);
        $this->assertSame('average', $average['tier']);

        $good = $parser->resolveFeaturedSnippetTableScore($buildTable(8));
        $this->assertSame(6, $good['points']);
        $this->assertSame('good', $good['tier']);

        $excellent = $parser->resolveFeaturedSnippetTableScore($buildTable(10));
        $this->assertSame(10, $excellent['points']);
        $this->assertTrue($excellent['passed']);
        $this->assertSame('excellent', $excellent['tier']);
    }

    public function test_calculate_seo_score_featured_snippet_partial_points(): void
    {
        $parser = $this->parser();

        $tableRows = "| H1 | H2 |\n| --- | --- |\n";
        for ($i = 1; $i <= 8; $i++) {
            $tableRows .= "| a{$i} | b{$i} |\n";
        }

        $score = $parser->calculateSeoScore($tableRows, []);

        $this->assertSame(6, $score['checklist']['table']['points']);
        $this->assertFalse($score['checklist']['table']['passed']);
        $this->assertSame('good', $score['checklist']['table']['tier']);
    }

    public function test_strip_panel_faqs_keeps_xem_them_in_body(): void
    {
        $parser = $this->parser();

        $html = <<<'HTML'
<p>Mở bài.</p>
<h2>Câu hỏi thường gặp</h2>
<h3>Chính sách giao hàng thế nào?</h3>
<p>Bảo hành 6–12 tháng.</p>
<h3>Xem thêm:</h3>
<ul><li><a href="/ykk">khóa kéo YKK</a></li></ul>
HTML;

        $stripped = $parser->stripPanelFaqsFromContent($html, ['Chính sách giao hàng thế nào?']);

        $this->assertStringContainsString('[omi_faq]', $stripped);
        $this->assertStringNotContainsString('Bảo hành 6–12 tháng', $stripped);
        $this->assertStringContainsString('href="/ykk"', $stripped);
        $this->assertStringContainsString('khóa kéo YKK', html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
