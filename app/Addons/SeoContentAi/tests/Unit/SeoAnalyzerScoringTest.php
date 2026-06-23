<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\SeoLinkMapType;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorHistoryService;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Services\SeoEngineService;
use App\Addons\SeoContentAi\Support\SeoLinkMapLinkTypeClassifier;
use Tests\TestCase;

final class SeoAnalyzerScoringTest extends TestCase
{
    public function test_engine_heading_rule_requires_two_h2_tags(): void
    {
        $engine = app(SeoEngineService::class);

        $none = $engine->analyzeHtml('<p>Chưa có heading</p>', 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);
        $one = $engine->analyzeHtml('<h2>Một</h2><p>Nội dung</p>', 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);
        $two = $engine->analyzeHtml('<h2>Một</h2><h2>Hai</h2><p>Nội dung</p>', 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);

        $this->assertContains('seo.heading', $none['reason_keys']);
        $this->assertContains('seo.heading', $one['reason_keys']);
        $this->assertNotContains('seo.heading', $two['reason_keys']);
    }

    public function test_engine_text_to_image_ideal_ratio(): void
    {
        $engine = app(SeoEngineService::class);
        $words = implode(' ', array_fill(0, 500, 'word'));
        $html = '<p>'.$words.'</p><img src="/a.jpg" alt="minh hoa"><img src="/b.jpg" alt="minh hoa 2">';

        $result = $engine->analyzeHtml($html, 'keyword', [], [
            'seo_title' => 'keyword',
            'meta_description' => 'keyword',
            'slug' => 'keyword',
        ]);

        $this->assertSame(15, $result['breakdown']['image_ratio']['earned'] ?? 0);
    }

    public function test_engine_text_to_image_penalizes_missing_alt(): void
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

    public function test_engine_wiki_trust_external_link_detection(): void
    {
        $engine = app(SeoEngineService::class);

        $trusted = $engine->analyzeHtml(
            '<p>Test</p><a href="https://en.wikipedia.org/wiki/Test">Wiki</a>',
            'keyword',
            [],
            ['seo_title' => 'keyword', 'meta_description' => 'keyword', 'slug' => 'keyword'],
        );
        $regular = $engine->analyzeHtml(
            '<p>Test</p><a href="https://example.com/page">Example</a>',
            'keyword',
            [],
            ['seo_title' => 'keyword', 'meta_description' => 'keyword', 'slug' => 'keyword'],
        );

        $this->assertSame(15, $trusted['breakdown']['wiki_trust']['earned'] ?? 0);
        $this->assertContains('seo.wiki_trust', $regular['reason_keys']);
    }

    public function test_custom_wiki_trust_domain_from_settings(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('wp_options')) {
            $this->markTestSkipped('wp_options table is not available in this test database.');
        }

        app(ArticleEditorHistoryService::class)->saveSettings([
            'wiki_trust_domains' => ['vnexpress.net', '*.gov'],
        ]);

        $this->assertTrue(SeoLinkMapLinkTypeClassifier::isWikiTrustHost('vnexpress.net'));
        $this->assertSame(
            SeoLinkMapType::WikiTrust,
            SeoLinkMapLinkTypeClassifier::forUnresolvedUrl('https://vnexpress.net/bai-viet'),
        );
    }

    public function test_persist_client_analysis_clamps_score_and_persists(): void
    {
        if (! \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('articles')) {
            $this->markTestSkipped('omi_seo_ai articles table is not available in this test database.');
        }

        $article = SeoArticle::query()->create([
            'site_id' => 1,
            'title' => 'Test article',
            'slug' => 'test-article',
            'type' => 'post',
            'status' => 'draft',
            'body' => '<h2>A</h2><h2>B</h2><p>Focus keyword content</p>',
        ]);

        $result = app(SeoAnalyzerService::class)->persistClientAnalysis(
            $article->fresh(),
            (string) $article->body,
            [
                'score' => 150,
                'good' => ['Rule pass +10'],
                'errors' => [],
                'warnings' => [],
                'content_bonus' => [
                    'faq_count' => 0,
                    'total_bonus' => 0,
                    'items' => [
                        'featured_snippet' => [
                            'key' => 'featured_snippet',
                            'label' => 'FEATURED SNIPPET',
                            'points' => 0,
                            'max_points' => 10,
                            'passed' => false,
                            'message' => 'Test',
                        ],
                        'faq' => [
                            'key' => 'faq',
                            'label' => 'FAQ',
                            'points' => 0,
                            'max_points' => 10,
                            'passed' => false,
                            'message' => 'Test',
                        ],
                    ],
                ],
                'extracted_links' => [
                    'internal' => [],
                    'external' => [],
                ],
            ],
        );

        $this->assertSame(100, $result['score']);
        $this->assertSame(100, (int) round((float) $article->fresh()?->seo_score));
    }
}
