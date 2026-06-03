<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\KeywordPhraseMatcher;
use PHPUnit\Framework\TestCase;

final class KeywordPhraseMatcherTest extends TestCase
{
    public function test_apostrophe_variants_match(): void
    {
        $content = 'May Túi Đeo Chéo KID’S CLUB cho thương hiệu thời trang.';

        $this->assertTrue(KeywordPhraseMatcher::contains($content, "túi đeo chéo kid's club"));
        $this->assertTrue(KeywordPhraseMatcher::contains($content, 'túi đeo chéo kids club'));
        $this->assertSame('túi đeo chéo kids club', KeywordPhraseMatcher::normalize("Túi Đeo Chéo KID'S CLUB"));
    }

    public function test_count_occurrences_ignores_punctuation(): void
    {
        $content = <<<'TEXT'
        Túi đeo chéo KID'S CLUB là sản phẩm hot.
        Nhiều shop chọn túi đeo chéo kids club.
        TEXT;

        $this->assertSame(2, KeywordPhraseMatcher::countOccurrences($content, "túi đeo chéo kid's club"));
    }
}
