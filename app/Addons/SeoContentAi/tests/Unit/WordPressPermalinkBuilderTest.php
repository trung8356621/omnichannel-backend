<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\WordPressSiteInfoService;
use App\Addons\SeoContentAi\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use Mockery;
use PHPUnit\Framework\TestCase;

final class WordPressPermalinkBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_detects_plain_permalink_urls(): void
    {
        $siteInfo = Mockery::mock(WordPressSiteInfoService::class);
        $builder = new WordPressPermalinkBuilder($siteInfo);

        $this->assertTrue($builder->isPlainPermalinkUrl('https://example.com/?p=10597'));
        $this->assertTrue($builder->isPlainPermalinkUrl('https://example.com/?page_id=12'));
        $this->assertFalse($builder->isPlainPermalinkUrl('https://example.com/my-post.html'));
    }

    public function test_builds_pretty_url_from_postname_html_structure(): void
    {
        $site = new Site([
            'domain' => 'maybalotuixachgiare.com',
            'ssl' => true,
        ]);

        $siteInfo = Mockery::mock(WordPressSiteInfoService::class);
        $siteInfo->shouldReceive('getStoredSiteInfo')
            ->andReturn([
                'permalink' => [
                    'structure' => '/%postname%.html',
                    'category_base' => 'category',
                    'tag_base' => 'tag',
                ],
            ]);

        $builder = new WordPressPermalinkBuilder($siteInfo);

        $url = $builder->resolveFromSyncItem($site, [
            'permalink' => 'https://maybalotuixachgiare.com/?p=10597',
            'slug' => 'vai-oxford-may-balo-thoi-trang',
            'type' => 'article',
            'published_at' => '2026-06-07T09:23:00+00:00',
            'wp_id' => 10597,
        ]);

        $this->assertSame(
            'https://maybalotuixachgiare.com/vai-oxford-may-balo-thoi-trang.html',
            $url,
        );
    }

    public function test_resolve_from_sync_keeps_pretty_permalink(): void
    {
        $site = new Site(['domain' => 'example.com', 'ssl' => true]);

        $siteInfo = Mockery::mock(WordPressSiteInfoService::class);
        $siteInfo->shouldReceive('getStoredSiteInfo')->andReturn([]);

        $builder = new WordPressPermalinkBuilder($siteInfo);

        $cached = 'https://example.com/my-slug.html';

        $this->assertSame($cached, $builder->resolveFromSyncItem($site, [
            'permalink' => $cached,
            'slug' => 'my-slug',
            'type' => 'article',
        ]));
    }
}
