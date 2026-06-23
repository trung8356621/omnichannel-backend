<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticlePostImagesService;
use Tests\TestCase;

final class ArticlePostImagesRestoreTest extends TestCase
{
    public function test_inject_into_empty_sections_inserts_images_after_h2(): void
    {
        $article = new SeoArticle(['site_id' => 1]);
        $html = <<<'HTML'
<p>Intro text</p>
<h2>Section A</h2>
<p>Body A</p>
<h2>Section B</h2>
<p>Body B</p>
HTML;

        $postImages = [
            [
                'wp_attachment_id' => 101,
                'src' => 'https://example.com/a.jpg',
                'slug' => 'image-a',
                'alt' => 'A',
            ],
            [
                'wp_attachment_id' => 102,
                'src' => 'https://example.com/b.jpg',
                'slug' => 'image-b',
                'alt' => 'B',
            ],
        ];

        $result = app(ArticlePostImagesService::class)
            ->injectIntoEmptySections($article, $html, $postImages);

        $this->assertSame(2, preg_match_all('/<img[\s>]/iu', $result));
        $this->assertStringContainsString('wp-image-101', $result);
        $this->assertStringContainsString('wp-image-102', $result);
        $this->assertStringContainsString('<h2>Section A</h2>', $result);
    }

    public function test_inject_skips_when_html_already_has_all_images(): void
    {
        $article = new SeoArticle(['site_id' => 1]);
        $html = '<h2>S</h2><figure><img src="https://example.com/a.jpg" class="wp-image-1" /></figure>';

        $postImages = [
            ['wp_attachment_id' => 1, 'src' => 'https://example.com/a.jpg', 'slug' => 'a'],
        ];

        $result = app(ArticlePostImagesService::class)
            ->injectIntoEmptySections($article, $html, $postImages);

        $this->assertSame($html, $result);
    }
}
