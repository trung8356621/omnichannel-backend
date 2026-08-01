<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectItemIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentProjectItemIdentityTest extends TestCase
{
    public function test_keyword_only_null_title_passes(): void
    {
        self::assertTrue(ContentProjectItemIdentity::isValid('nghệ thuật Typography', null));
    }

    public function test_keyword_only_empty_title_passes(): void
    {
        self::assertTrue(ContentProjectItemIdentity::isValid('nghệ thuật Typography', ''));
    }

    public function test_title_only_null_keyword_passes(): void
    {
        self::assertTrue(ContentProjectItemIdentity::isValid(null, 'Cách chọn balo'));
    }

    public function test_title_only_empty_keyword_passes(): void
    {
        self::assertTrue(ContentProjectItemIdentity::isValid('', 'Cách chọn balo'));
    }

    public function test_both_filled_passes(): void
    {
        self::assertTrue(ContentProjectItemIdentity::isValid('seo', 'SEO guide'));
    }

    public function test_both_null_fails(): void
    {
        self::assertFalse(ContentProjectItemIdentity::isValid(null, null));
    }

    public function test_both_empty_or_whitespace_fails(): void
    {
        self::assertFalse(ContentProjectItemIdentity::isValid('', ''));
        self::assertFalse(ContentProjectItemIdentity::isValid('   ', "\t"));
    }

    public function test_assert_valid_throws_on_both_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vui lòng nhập ít nhất Từ khóa hoặc Tiêu đề.');
        ContentProjectItemIdentity::assertValid('  ', null);
    }

    public function test_topic_prefers_post_title(): void
    {
        self::assertSame('Title', ContentProjectItemIdentity::topic('Title', 'keyword'));
        self::assertSame('keyword', ContentProjectItemIdentity::topic(null, 'keyword'));
        self::assertSame('keyword', ContentProjectItemIdentity::topic('  ', 'keyword'));
    }

    public function test_normalize_trims(): void
    {
        self::assertSame('abc', ContentProjectItemIdentity::normalize('  abc  '));
        self::assertSame('', ContentProjectItemIdentity::normalize(null));
    }
}
