<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleGoogleSerpPreviewService;
use App\Support\RankMathSchemaParser;
use PHPUnit\Framework\TestCase;

final class ArticleGoogleSerpPreviewServiceTest extends TestCase
{
    public function test_builds_product_preview_from_synthetic_schema(): void
    {
        $article = new SeoArticle([
            'title' => 'Balo học sinh',
            'type' => 'product',
        ]);

        $preview = (new ArticleGoogleSerpPreviewService(new RankMathSchemaParser))->buildForArticle(
            $article,
            'Tiêu đề SEO tùy chỉnh',
            'Mô tả ngắn cho Google.',
            'https://shop.test/san-pham/balo',
        );

        $this->assertSame('product', $preview['type']);
        $this->assertSame('Tiêu đề SEO tùy chỉnh', $preview['title']);
        $this->assertSame('Mô tả ngắn cho Google.', $preview['description']);
        $this->assertStringContainsString('shop.test', $preview['display_url']);
    }
}
