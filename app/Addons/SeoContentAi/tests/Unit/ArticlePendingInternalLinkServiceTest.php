<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArticlePendingInternalLinkService;
use Tests\TestCase;

final class ArticlePendingInternalLinkServiceTest extends TestCase
{
    public function test_replace_placeholder_in_html_swaps_hash_href(): void
    {
        $service = app(ArticlePendingInternalLinkService::class);

        $html = '<p>Đọc thêm <a href="#a1b2c3d4">thời trang bền vững</a> nhé.</p>';
        $next = $service->replacePlaceholderInHtml($html, 'a1b2c3d4', 'https://shop.test/thoi-trang-ben-vung');

        $this->assertStringContainsString('href="https://shop.test/thoi-trang-ben-vung"', $next);
        $this->assertStringNotContainsString('#a1b2c3d4', $next);
    }
}
