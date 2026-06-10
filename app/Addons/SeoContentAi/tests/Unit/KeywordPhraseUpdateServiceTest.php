<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use App\Addons\SeoContentAi\Services\KeywordPhraseUpdateService;
use PHPUnit\Framework\TestCase;

final class KeywordPhraseUpdateServiceTest extends TestCase
{
    public function test_it_replaces_only_matching_internal_link_anchor(): void
    {
        $html = '<p><a href="https://example.com/balo">balo đẹp</a> và '
            .'<a href="https://example.com/khac">balo đẹp</a></p>';

        $updated = (new KeywordPhraseUpdateService(new ArticleWordPressSyncFlagService))->replaceAnchorsInHtml(
            $html,
            ['https://example.com/balo'],
            'balo đẹp',
            'balo thời trang',
        );

        self::assertStringContainsString(
            '<a href="https://example.com/balo">balo thời trang</a>',
            $updated,
        );
        self::assertStringContainsString(
            '<a href="https://example.com/khac">balo đẹp</a>',
            $updated,
        );
    }
}
