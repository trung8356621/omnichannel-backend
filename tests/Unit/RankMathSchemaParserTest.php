<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RankMathSchemaParser;
use PHPUnit\Framework\TestCase;

final class RankMathSchemaParserTest extends TestCase
{
    public function test_parses_product_with_aggregate_offer_and_rating(): void
    {
        $json = json_encode([
            '@graph' => [
                [
                    '@type' => 'Product',
                    'name' => 'Balo học sinh',
                    'description' => 'Mô tả sản phẩm ngắn.',
                    'url' => 'https://shop.test/san-pham/balo',
                    'aggregateRating' => [
                        'ratingValue' => '4.6',
                        'reviewCount' => '128',
                    ],
                    'offers' => [
                        '@type' => 'AggregateOffer',
                        'lowPrice' => '199000',
                        'highPrice' => '299000',
                        'priceCurrency' => 'VND',
                        'availability' => 'https://schema.org/InStock',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = (new RankMathSchemaParser)->parse($json);

        $this->assertSame('product', $parsed['type']);
        $this->assertSame('Balo học sinh', $parsed['title']);
        $this->assertStringContainsString('199', $parsed['meta']['price']);
        $this->assertSame(4.6, $parsed['meta']['rating_value']);
        $this->assertSame(128, $parsed['meta']['review_count']);
        $this->assertSame('In stock', $parsed['meta']['availability_label']);
    }

    public function test_parses_article_with_headline(): void
    {
        $json = json_encode([
            '@graph' => [
                [
                    '@type' => 'BlogPosting',
                    'headline' => 'Hướng dẫn chọn balo',
                    'description' => 'Nội dung meta.',
                    'url' => 'https://blog.test/post/1',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = (new RankMathSchemaParser)->parse($json);

        $this->assertSame('article', $parsed['type']);
        $this->assertSame('Hướng dẫn chọn balo', $parsed['title']);
        $this->assertSame('Nội dung meta.', $parsed['description']);
    }

    public function test_returns_empty_for_invalid_json(): void
    {
        $parsed = (new RankMathSchemaParser)->parse('{not-json');

        $this->assertSame('unknown', $parsed['type']);
        $this->assertSame('', $parsed['title']);
    }

    public function test_resolves_graph_id_references_for_offer_and_rating(): void
    {
        $json = json_encode([
            '@graph' => [
                [
                    '@type' => 'Product',
                    '@id' => 'https://shop.test/product#product',
                    'name' => 'Balo',
                    'offers' => ['@id' => 'https://shop.test/product#offer'],
                    'aggregateRating' => ['@id' => 'https://shop.test/product#rating'],
                ],
                [
                    '@type' => 'AggregateOffer',
                    '@id' => 'https://shop.test/product#offer',
                    'lowPrice' => '150000',
                    'highPrice' => '250000',
                    'priceCurrency' => 'VND',
                ],
                [
                    '@type' => 'AggregateRating',
                    '@id' => 'https://shop.test/product#rating',
                    'ratingValue' => '4.8',
                    'reviewCount' => '12',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = (new RankMathSchemaParser)->parse($json);

        $this->assertSame('product', $parsed['type']);
        $this->assertStringContainsString('150', $parsed['meta']['price']);
        $this->assertSame(4.8, $parsed['meta']['rating_value']);
        $this->assertSame(12, $parsed['meta']['review_count']);
    }
}
