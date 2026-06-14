<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\Keyword;
use PHPUnit\Framework\TestCase;

class KeywordPhraseDecodeTest extends TestCase
{
    public function test_decode_phrase_converts_html_entities(): void
    {
        $this->assertSame(
            'Đặt in túi vải & balo quảng cáo ngay',
            Keyword::decodePhrase('Đặt in túi vải &amp; balo quảng cáo ngay'),
        );
    }

    public function test_decode_phrase_keeps_plain_text(): void
    {
        $this->assertSame('balo quảng cáo', Keyword::decodePhrase('balo quảng cáo'));
    }
}
