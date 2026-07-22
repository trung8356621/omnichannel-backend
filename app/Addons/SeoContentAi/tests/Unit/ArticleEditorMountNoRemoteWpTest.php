<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static contract: EditArticle mount must not call remote WordPress helpers.
 */
final class ArticleEditorMountNoRemoteWpTest extends TestCase
{
    public function test_mount_and_hydrate_source_omit_remote_wordpress_calls(): void
    {
        $path = dirname(__DIR__, 2).'/Filament/Resources/ArticleResource/Pages/EditArticle.php';
        $source = (string) file_get_contents($path);

        $mountPos = strpos($source, 'public function mount(int|string $record): void');
        $hydratePos = strpos($source, 'protected function hydrateArticleState(): void');
        $pollPos = strpos($source, 'public function pollEditorReadiness(): void');

        self::assertNotFalse($mountPos);
        self::assertNotFalse($hydratePos);
        self::assertNotFalse($pollPos);

        $mountBlock = substr($source, $mountPos, $hydratePos - $mountPos);
        $pollEnd = strpos($source, 'public function updatedArticleSlug', $pollPos);
        $pollBlock = substr($source, $pollPos, ($pollEnd !== false ? $pollEnd : $pollPos + 800) - $pollPos);
        $hydrateEnd = strpos($source, 'private function restoreArticleBodyFromWordPressCacheIfMissing', $hydratePos);
        $hydrateBlock = substr($source, $hydratePos, ($hydrateEnd !== false ? $hydrateEnd : $hydratePos + 1200) - $hydratePos);

        foreach (['syncTitleFromWordPressWhenAllowed', 'syncWordPressCategoriesOnLoad', 'importFaqsFromWordPressOnLoad'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden.'(',
                $mountBlock,
                "mount() must not call {$forbidden}",
            );
            self::assertStringNotContainsString(
                $forbidden.'(',
                $pollBlock,
                "pollEditorReadiness() must not call {$forbidden}",
            );
        }

        self::assertStringNotContainsString(
            'healTaxonomyMetaFromWordPress',
            $hydrateBlock,
            'hydrateArticleState() must not call healTaxonomyMetaFromWordPress (remote HTTP)',
        );

        self::assertStringContainsString('protected string $bootstrapEditorHtml', $source);
        self::assertStringNotContainsString('public string $editorHtml', $source);
        self::assertStringContainsString('forEditorBootstrap', $source);
    }
}
