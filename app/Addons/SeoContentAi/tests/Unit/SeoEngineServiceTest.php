<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\SeoLinkMapType;
use App\Addons\SeoContentAi\Support\SeoLinkMapLinkTypeClassifier;
use App\Services\SeoEngineService;
use Tests\TestCase;

final class SeoEngineServiceTest extends TestCase
{
    public function test_additive_score_awards_heading_points_for_two_h2_tags(): void
    {
        $engine = app(SeoEngineService::class);

        $html = '<h2>One</h2><h2>Two</h2><p>Focus keyword sample content here.</p>';
        $result = $engine->analyzeHtml(
            $html,
            'focus keyword',
            [],
            [
                'seo_title' => 'Focus keyword title',
                'meta_description' => 'Focus keyword description',
                'slug' => 'focus-keyword-sample',
            ],
        );

        $this->assertSame(20, $result['breakdown']['heading']['earned'] ?? 0);
        $this->assertNotContains('seo.heading', $result['reason_keys']);
    }

    public function test_length_scoring_uses_article_length_target(): void
    {
        $engine = app(SeoEngineService::class);
        $words900 = implode(' ', array_fill(0, 900, 'word'));
        $words1100 = implode(' ', array_fill(0, 1100, 'word'));

        $belowTarget = $engine->analyzeHtml("<p>{$words900}</p>", 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
            'article_length_target' => 1000,
        ]);
        $meetsTarget = $engine->analyzeHtml("<p>{$words1100}</p>", 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
            'article_length_target' => 1000,
        ]);

        $this->assertSame(0, $belowTarget['breakdown']['length']['earned'] ?? 0);
        $this->assertContains('seo.length', $belowTarget['reason_keys']);
        $this->assertSame(15, $meetsTarget['breakdown']['length']['earned'] ?? 0);
        $this->assertNotContains('seo.length', $meetsTarget['reason_keys']);
    }

    public function test_text_to_image_ideal_ratio_scores_fifteen(): void
    {
        $engine = app(SeoEngineService::class);
        $words = implode(' ', array_fill(0, 500, 'word'));
        $html = '<p>'.$words.'</p><img src="/a.jpg" alt="ok"><img src="/b.jpg" alt="ok2">';

        $result = $engine->analyzeHtml($html, 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);

        $this->assertSame(15, $result['breakdown']['image_ratio']['earned'] ?? 0);
        $this->assertSame(250, $this->extractImageRatio($result));
    }

    public function test_text_to_image_penalizes_missing_alt(): void
    {
        $engine = app(SeoEngineService::class);
        $words = implode(' ', array_fill(0, 500, 'word'));
        $html = '<p>'.$words.'</p><img src="/a.jpg" alt="ok"><img src="/b.jpg">';

        $result = $engine->analyzeHtml($html, 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);

        $this->assertSame(10, $result['breakdown']['image_ratio']['earned'] ?? 0);
    }

    public function test_wiki_trust_link_is_detected(): void
    {
        $engine = app(SeoEngineService::class);
        $html = '<p>Content</p><a href="https://en.wikipedia.org/wiki/Test">Wiki</a>';

        $result = $engine->analyzeHtml($html, 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);

        $this->assertSame(15, $result['breakdown']['wiki_trust']['earned'] ?? 0);
        $this->assertTrue(SeoLinkMapLinkTypeClassifier::forUnresolvedUrl('https://en.wikipedia.org/wiki/Test') === SeoLinkMapType::WikiTrust);
    }

    public function test_faq_schema_adds_points(): void
    {
        $engine = app(SeoEngineService::class);
        $html = '<p>keyword body</p>';
        $faqs = [['question' => 'Q?', 'answer' => 'A.']];

        $result = $engine->analyzeHtml($html, 'keyword', $faqs, [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);

        $this->assertArrayNotHasKey('featured_snippet', $result['breakdown']);
        $this->assertSame(10, $result['breakdown']['faq_schema']['earned'] ?? 0);
    }

    public function test_faq_schema_detects_shortcode_without_panel_rows(): void
    {
        $engine = app(SeoEngineService::class);
        $html = '<h2>FAQ</h2><p class="omi-faq-placeholder" data-omi-faq="1">[omi_faq]</p>';

        $result = $engine->analyzeHtml($html, 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);

        $this->assertSame(10, $result['breakdown']['faq_schema']['earned'] ?? 0);
        $this->assertNotContains('seo.faq_schema', $result['reason_keys']);
    }

    public function test_faq_schema_detects_inline_h3_paragraph_pairs(): void
    {
        $engine = app(SeoEngineService::class);
        $html = <<<'HTML'
<h2>Câu hỏi thường gặp</h2>
<h3>Túi handmade có bền không?</h3>
<p>Có, nếu bảo quản đúng cách.</p>
HTML;

        $result = $engine->analyzeHtml($html, 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);

        $this->assertSame(10, $result['breakdown']['faq_schema']['earned'] ?? 0);
    }

    public function test_total_score_is_capped_at_one_hundred(): void
    {
        $engine = app(SeoEngineService::class);
        $words = implode(' ', array_fill(0, 1300, 'word'));
        $html = '<h2>A</h2><h2>B</h2><table><tr><th>X</th><th>Y</th></tr><tr><td>1</td><td>2</td></tr></table>'
            .'<p>'.$words.' keyword keyword keyword</p>'
            .'<img src="/a.jpg" alt="keyword"><img src="/b.jpg" alt="keyword">'
            .'<a href="https://en.wikipedia.org/wiki/Test">Wiki</a>';

        $result = $engine->analyzeHtml($html, 'keyword', [['question' => 'Q', 'answer' => 'A']], [
            'seo_title' => 'keyword title',
            'meta_description' => 'keyword meta',
            'slug' => 'keyword-slug',
            'article_length_target' => 1200,
        ]);

        $this->assertLessThanOrEqual(100, $result['seo_score']);
        $this->assertGreaterThanOrEqual(80, $result['seo_score']);
    }

    public function test_reason_keys_use_lang_prefix_not_raw_text(): void
    {
        $engine = app(SeoEngineService::class);

        $result = $engine->analyzeHtml('<p>short</p>', 'keyword', [], [
            'seo_title' => 'title',
            'meta_description' => 'desc',
            'slug' => 'slug',
        ]);

        foreach ($result['reason_keys'] as $key) {
            $this->assertStringStartsWith('seo.', $key);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractImageRatio(array $result): int
    {
        $params = $result['breakdown']['image_ratio']['params'] ?? [];

        return (int) ($params['ratio'] ?? 0);
    }
}
