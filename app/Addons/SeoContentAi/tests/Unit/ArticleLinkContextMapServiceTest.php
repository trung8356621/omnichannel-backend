<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArticleLinkContextMapService;
use App\Addons\SeoContentAi\Support\KeywordSyncIsolation;
use Tests\TestCase;

final class ArticleLinkContextMapServiceTest extends TestCase
{
    public function test_extract_anchors_with_context_includes_surrounding_text(): void
    {
        $service = app(ArticleLinkContextMapService::class);

        $html = '<p>Trước anchor này có nội dung dài để test context. '
            .'<a href="/san-pham-a">sản phẩm A</a> '
            .'sau anchor cũng có thêm văn bản mô tả.</p>';

        $anchors = $service->extractAnchorsWithContext($html);

        $this->assertCount(1, $anchors);
        $this->assertSame('sản phẩm A', $anchors[0]['anchor_text']);
        $this->assertSame('/san-pham-a', $anchors[0]['href']);
        $this->assertNotNull($anchors[0]['context_before']);
        $this->assertNotNull($anchors[0]['context_after']);
    }

    public function test_automatic_keyword_sync_is_locked_by_default(): void
    {
        $this->assertFalse(KeywordSyncIsolation::allowsAutomaticContentSync());
        $this->assertFalse(KeywordSyncIsolation::allowsContentKeywordPersistence());
        $this->assertTrue(KeywordSyncIsolation::allowsDomainResync());
    }

    public function test_domain_resync_context_enables_keyword_persistence_gate(): void
    {
        KeywordSyncIsolation::runWithinDomainResync(function (): void {
            $this->assertTrue(KeywordSyncIsolation::allowsContentKeywordPersistence());
        });

        $this->assertFalse(KeywordSyncIsolation::allowsContentKeywordPersistence());
    }
}
