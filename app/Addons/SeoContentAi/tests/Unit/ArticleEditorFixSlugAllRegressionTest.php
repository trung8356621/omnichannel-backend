<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Fix Slug All eligibility + no-op UX (media classification regression).
 */
final class ArticleEditorFixSlugAllRegressionTest extends TestCase
{
    private function readAddon(string $relative): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);
        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }

    public function test_https_alone_is_not_wordpress_protected(): void
    {
        $source = $this->readAddon('resources/js/utils/mediaSourceClassification.js');

        self::assertStringContainsString('hasTrustedWordPressUrl', $source);
        // JS regex escapes slashes: /\/wp-content\/uploads\//i
        self::assertStringContainsString('wp-content\\/uploads', $source);
        // Bare https must not classify as WordPress.
        self::assertStringNotContainsString('/^https?:', $source);
        self::assertStringContainsString('Local/SEO media before WP URL heuristics', $source);
        self::assertStringContainsString('seoMediaId > 0', $source);
    }

    public function test_bulk_eligible_excludes_wp_includes_local_generated_uploaded(): void
    {
        $source = $this->readAddon('resources/js/utils/mediaSourceClassification.js');

        self::assertStringContainsString("source === 'local'", $source);
        self::assertStringContainsString("source === 'generated'", $source);
        self::assertStringContainsString("source === 'uploaded'", $source);
        self::assertStringContainsString('isWordPressProtectedMedia(row)', $source);
    }

    public function test_fix_slug_all_reports_noop_reasons(): void
    {
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');
        $utils = $this->readAddon('resources/js/utils/articleImagesUtils.js');
        $i18n = $this->readAddon('resources/js/utils/i18n.js');
        $api = $this->readAddon('resources/js/utils/seoMediaApi.js');
        $controller = $this->readAddon('Http/Controllers/ArticleEditorOperationController.php');

        self::assertStringContainsString('editor_quick_fix_slug_all_noop_no_local', $editor);
        self::assertStringContainsString('editor_quick_fix_slug_all_noop_already_valid', $editor);
        self::assertStringContainsString('editor_quick_fix_slug_all_noop_mixed', $editor);
        self::assertStringContainsString('skippedAlreadyValid', $utils);
        self::assertStringContainsString('eligibleCount', $utils);
        self::assertStringContainsString('includeWordPressRenames: false', $editor);
        self::assertStringContainsString('fixArticleMediaSlugs', $editor);
        self::assertStringContainsString('fix-media-slugs', $api);
        self::assertStringContainsString('wordpress_media_requires_explicit_rename', $this->readAddon('Services/SeoMediaArticleSlugFixService.php'));
        self::assertStringContainsString('fixMediaSlugs', $controller);
        self::assertStringContainsString('editor_quick_fix_slug_all_noop_no_local', $i18n);
    }

    public function test_wp_media_never_enqueued_for_bulk_fix_slug(): void
    {
        $utils = $this->readAddon('resources/js/utils/articleImagesUtils.js');

        self::assertStringContainsString('isWordPressProtectedMedia(row) || !isBulkSlugRenameSafeMedia(row)', $utils);
        self::assertStringContainsString('WordPress media: never enqueue for Fix Slug All', $utils);
        self::assertStringContainsString('includeWordPressRenames: false', $utils);
    }
}
