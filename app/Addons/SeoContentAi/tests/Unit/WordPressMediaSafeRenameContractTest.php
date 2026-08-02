<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages\EditArticle;
use App\Addons\SeoContentAi\Http\Controllers\WordPressMediaRenameController;
use App\Addons\SeoContentAi\Services\SeoMediaArticleSlugFixService;
use App\Addons\SeoContentAi\Services\WordPress\WordPressMediaRenameService;
use App\Addons\SeoContentAi\Services\WordPressAttachmentRenameService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class WordPressMediaSafeRenameContractTest extends TestCase
{
    public function test_fix_slug_all_never_bulk_renames_wordpress_media(): void
    {
        $batch = $this->methodSource(
            new ReflectionMethod(WordPressAttachmentRenameService::class, 'renameBatch'),
        );
        self::assertStringContainsString('wordpress_media_requires_explicit_rename', $batch);

        $edit = $this->methodSource(
            new ReflectionMethod(EditArticle::class, 'renameAttachmentSlugsOnWordPress'),
        );
        self::assertStringContainsString('rejectBulkWordPressRename', $edit);

        $localFix = (string) file_get_contents(
            (new ReflectionClass(SeoMediaArticleSlugFixService::class))->getFileName(),
        );
        self::assertStringContainsString('wordpress_media_requires_explicit_rename', $localFix);

        $js = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/articleImagesUtils.js',
        );
        self::assertStringContainsString('includeWordPressRenames: false', $js);
        self::assertStringContainsString('isWordPressProtectedMedia', $js);
    }

    public function test_except_ui_removed_from_images_tab(): void
    {
        $tab = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/ArticleImagesTab.jsx',
        );
        self::assertStringNotContainsString("t('except')", $tab);
        self::assertStringNotContainsString('excludeQuickFix: !excluded', $tab);
        self::assertStringContainsString('wp_media_bulk_protected', $tab);
        self::assertStringContainsString('wp_media_rename_menu', $tab);

        $i18n = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/i18n.js',
        );
        // dead Except action copy may remain as unused key briefly; button must be gone.
        self::assertStringNotContainsString('image_except_enable_hint', $tab);
    }

    public function test_explicit_rename_requires_strong_confirmation(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(WordPressMediaRenameService::class))->getFileName(),
        );
        self::assertStringContainsString("CONFIRMATION_PHRASE = 'RENAME'", $source);
        self::assertStringContainsString('acknowledge_url_change', $source);
        self::assertStringContainsString('confirmation_required', $source);
        self::assertStringContainsString('usage_scan_incomplete', $source);
        self::assertStringContainsString('partial_failure', $source);
        self::assertStringContainsString('wordpress_media_rename_audit', $source);

        $controller = (string) file_get_contents(
            (new ReflectionClass(WordPressMediaRenameController::class))->getFileName(),
        );
        self::assertStringContainsString('acknowledge_url_change', $controller);
        self::assertStringContainsString('confirmation_phrase', $controller);
    }

    public function test_media_library_and_editor_share_rename_service(): void
    {
        $library = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/pages/media-library.blade.php',
        );
        self::assertStringContainsString('seo-wordpress-media-rename-open', $library);
        self::assertStringContainsString('Đổi tên ảnh', $library);

        $modal = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/WordPressMediaRenameModal.jsx',
        );
        self::assertStringContainsString('/api/seo/media/wordpress/rename', $modal);
        self::assertStringContainsString("phrase.trim() === 'RENAME'", $modal);

        $editor = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString('WordPressMediaRenameModal', $editor);
        $mediaPage = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/media-library-page.jsx',
        );
        self::assertStringContainsString('WordPressMediaRenameModal', $mediaPage);
    }

    public function test_images_health_separates_integrity_warning_info(): void
    {
        $health = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/assistantWidgetHealth.js',
        );
        self::assertStringContainsString('error_count', $health);
        self::assertStringContainsString('warning_count', $health);
        self::assertStringContainsString('info_count', $health);
        self::assertStringContainsString('local_slug_placeholder', $health);
        self::assertStringContainsString('image_ratio_low', $health);
        self::assertStringContainsString("severity: 'info'", $health);
        self::assertStringContainsString('isWordPressProtectedMedia', $health);
        self::assertStringContainsString('WP filename ≠ keyword is NOT a warning', $health);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
