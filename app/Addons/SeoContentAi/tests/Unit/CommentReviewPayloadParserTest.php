<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\CommentReviewPayloadParser;
use PHPUnit\Framework\TestCase;

final class CommentReviewPayloadParserTest extends TestCase
{
    public function test_parses_vietnamese_keys_from_json_array(): void
    {
        $parser = new CommentReviewPayloadParser();

        $raw = <<<'JSON'
[
  {
    "comment": "Balo xịn quá",
    "Họ và tên": "Nguyễn Thu Thảo",
    "Email": "thuthao@example.com"
  }
]
JSON;

        $items = $parser->parse($raw);

        $this->assertCount(1, $items);
        $this->assertSame('Balo xịn quá', $items[0]['content']);
        $this->assertSame('Nguyễn Thu Thảo', $items[0]['author']);
        $this->assertSame('thuthao@example.com', $items[0]['email']);
        $this->assertArrayNotHasKey('rating', $items[0]);
    }
}
