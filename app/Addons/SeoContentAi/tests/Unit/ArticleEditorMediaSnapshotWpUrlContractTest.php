<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: media snapshot URL lookup must not query non-existent seo_media.wp_url.
 */
final class ArticleEditorMediaSnapshotWpUrlContractTest extends TestCase
{
    public function test_find_seo_media_does_not_query_wp_url_column(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleEditor/ArticleEditorMediaSnapshotService.php',
        );

        self::assertStringContainsString('private function findSeoMedia(', $source);
        self::assertStringNotContainsString("orWhere('wp_url'", $source);
        self::assertStringNotContainsString('where(\'wp_url\'', $source);
        self::assertStringContainsString("orWhere('path', \$relativePath)", $source);
        self::assertStringContainsString("orWhere('url', \$path)", $source);
    }
}
