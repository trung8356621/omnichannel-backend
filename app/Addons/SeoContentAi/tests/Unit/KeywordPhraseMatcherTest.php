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

    public function test_keyword_missing_in_meta_is_case_insensitive(): void
    {
        $meta = 'Mô tả sản phẩm túi đeo chéo kids club chính hãng.';

        $this->assertTrue(KeywordPhraseMatcher::contains($meta, 'TÚI ĐEO CHÉO KIDS CLUB'));
        $this->assertTrue(KeywordPhraseMatcher::contains($meta, mb_strtolower('TÚI ĐEO CHÉO KIDS CLUB', 'UTF-8')));
    }

    public function test_seo_analyzer_lowercases_keyword_before_meta_check(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/seoAnalyzer.js',
        );

        self::assertStringContainsString('toLocaleLowerCase()', $source);
        self::assertStringContainsString('keywordForMatch', $source);
        self::assertStringContainsString('containsKeywordPhrase(metaDescription, keywordForMatch)', $source);
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
