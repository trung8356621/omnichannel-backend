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
}
