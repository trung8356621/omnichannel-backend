<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\WordPress\WordPressManualSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: Media Health ≠ SEO analysis; Content Project hides/blocks Manual Sync WP.
 */
final class ArticleEditorMediaHealthSyncContractTest extends TestCase
{
    public function test_images_media_health_ignores_seo_ratio_for_error_status(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/assistantWidgetHealth.js',
        );
        $imagesFn = $this->extractBetween(
            $source,
            'export function buildImagesWidgetHealth({',
            'export function buildSeoWidgetHealth({',
        );
        $analyzeFn = $this->extractBetween(
            $source,
            'export function analyzeImageRowsHealth(rows, keyword = \'\') {',
            'export function buildImagesWidgetHealth({',
        );

        self::assertStringContainsString('rowHasUnresolvedMediaSlug', $source);
        self::assertStringContainsString('error_count', $imagesFn);
        self::assertStringContainsString('warning_count', $imagesFn);
        self::assertStringContainsString('info_count', $imagesFn);
        self::assertStringContainsString("severity: 'info'", $imagesFn);
        self::assertStringContainsString('image_ratio_low', $imagesFn);
        self::assertStringContainsString("status = 'error'", $imagesFn);
        self::assertStringContainsString("status = 'warning'", $imagesFn);
        self::assertStringContainsString("status = 'info'", $imagesFn);
        // SEO ratio / recommendation must not inflate integrity error status.
        self::assertStringNotContainsString(
            'fixableIssues + (missingRecommended > 0 ? 1 : 0)',
            $source,
        );
        self::assertStringContainsString(
            'WP filename ≠ keyword is NOT a warning',
            $analyzeFn,
        );
        self::assertStringContainsString('local_slug_placeholder', $analyzeFn);
        self::assertStringContainsString('isWordPressProtectedMedia', $analyzeFn);
        self::assertStringContainsString('image_reference_invalid', $analyzeFn);
        self::assertStringNotContainsString('isImageReadyForWpSlugFix', $source);
        self::assertStringNotContainsString('const issueCount = fixableIssues;', $source);
    }

    public function test_featured_presence_clears_featured_missing_hard_error(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/assistantWidgetHealth.js',
        );
        $fn = $this->extractBetween(
            $source,
            'export function buildFeaturedWidgetHealth({',
            'export function buildGalleryWidgetHealth({',
        );

        self::assertStringContainsString('featured_missing', $fn);
        self::assertStringContainsString('hardErrors', $fn);
        self::assertStringContainsString('issue_count: hardErrors.length', $fn);
        self::assertStringContainsString('onlySoftWarnings', $fn);
        self::assertStringContainsString("status = 'warning'", $fn);
    }

    public function test_slug_and_featured_mutations_bump_media_health_tick(): void
    {
        $editor = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertStringContainsString('setMediaHealthTick', $editor);
        self::assertStringContainsString('seo-assistant-widget-health-refresh', $editor);
        self::assertStringContainsString('seo-featured-image-updated', $editor);
        self::assertStringContainsString('finalizeSlugRenameSideEffects', $editor);
        self::assertStringContainsString('setFeaturedHealthSnapshot(loadFeaturedImage(articleId))', $editor);
    }

    public function test_manual_sync_api_blocked_for_content_project(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(WordPressManualSyncService::class, 'enqueueFromEditorBundle'),
        );

        self::assertStringContainsString('belongsToContentProject', $source);
        self::assertStringContainsString('content_project_manual_sync_forbidden', $source);
        self::assertStringContainsString('return $this->blocked(', $source);
        $projectBranch = $this->extractBetween(
            $source,
            'if ($this->contentProjectMembership->belongsToContentProject($article)) {',
            '$bundle = $this->syncQueue->applyPublishImmediatelyToBundle($bundle);',
        );
        self::assertStringNotContainsString('enqueueManual', $projectBranch);
        self::assertStringNotContainsString('ManualWordPressSyncJob', $projectBranch);
    }

    public function test_independent_article_keeps_sync_wp_button(): void
    {
        $actions = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
        );

        self::assertStringContainsString('@else', $actions);
        self::assertStringContainsString('data-seo-page-action="sync"', $actions);
        self::assertStringContainsString('data-seo-sync-mode="wordpress_sync"', $actions);
        self::assertStringContainsString('data-seo-page-action="save-close"', $actions);
        self::assertStringContainsString('@if ($inContentProject)', $actions);
    }

    public function test_archived_content_project_membership_still_blocks_manual_sync_visibility(): void
    {
        $membership = (string) file_get_contents(
            (new ReflectionClass(
                \App\Addons\SeoContentAi\Services\ContentProject\ContentProjectArticleMembership::class,
            ))->getFileName(),
        );

        self::assertStringContainsString('function belongsToContentProject', $membership);
        self::assertStringContainsString('assignedTaskForArticle', $membership);
        // Assigned task query must NOT require whereNull(archived_at).
        $assigned = $this->extractBetween(
            $membership,
            'public function assignedTaskForArticle',
            'public function belongsToContentProject',
        );
        self::assertStringNotContainsString("whereNull('archived_at')", $assigned);
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

    private function extractBetween(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        self::assertNotFalse($from, 'start marker missing: '.$start);
        $to = strpos($haystack, $end, $from);
        self::assertNotFalse($to, 'end marker missing: '.$end);

        return substr($haystack, $from, $to - $from);
    }
}
