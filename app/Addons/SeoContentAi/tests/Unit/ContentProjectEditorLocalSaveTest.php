<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus;
use App\Addons\SeoContentAi\Extension\Builtin\Wordpress\WordPressPublisher;
use App\Addons\SeoContentAi\Http\Controllers\ArticleEditorSyncController;
use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPublishTransitionGuard;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueService;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectWorkspaceSaveService;
use App\Addons\SeoContentAi\Services\WordPress\WordPressManualSyncService;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: Content Project editor Save = Laravel-only; close only after proven persist;
 * Published ≠ latest local; Publish Now updates existing WP post.
 */
final class ContentProjectEditorLocalSaveTest extends TestCase
{
    public function test_manual_sync_routes_project_articles_to_workspace_save_only(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(WordPressManualSyncService::class, 'enqueueFromEditorBundle'),
        );

        self::assertStringContainsString('belongsToActiveContentProject', $source);
        self::assertStringContainsString('workspaceSave->saveFromEditorBundle', $source);

        $projectBranch = $this->extractBetween(
            $source,
            'if ($this->contentProjectMembership->belongsToActiveContentProject($article)) {',
            '$bundle = $this->syncQueue->applyPublishImmediatelyToBundle($bundle);',
        );
        self::assertStringContainsString('return $this->workspaceSave->saveFromEditorBundle', $projectBranch);
        self::assertStringNotContainsString('enqueueManual', $projectBranch);
        self::assertStringNotContainsString('ManualWordPressSyncJob', $projectBranch);
    }

    public function test_workspace_save_returns_canonical_project_local_save_result(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectWorkspaceSaveService::class))->getFileName(),
        );

        self::assertStringContainsString("SAVE_MODE = 'project_local_save'", $source);
        self::assertStringContainsString("DB::connection('omi_seo_ai')->transaction", $source);
        self::assertStringContainsString('Không bọc content.update trong TX dài', $source);
        self::assertStringContainsString('persist_hash_mismatch', $source);
        self::assertStringContainsString('content_hash', $source);
        self::assertStringContainsString('project_task_id', $source);
        self::assertStringContainsString("'queued' => false", $source);
        self::assertStringContainsString("'wp_api_called' => false", $source);
        self::assertStringContainsString('markLocalEditPending', $source);
        self::assertStringContainsString('rememberLocalContentHash', $source);
        self::assertStringContainsString("'close_editor' => false", $source);
        self::assertStringContainsString("'close_editor' => true", $source);
        self::assertStringNotContainsString('ManualWordPressSyncJob', $source);
        self::assertStringNotContainsString('enqueueManual', $source);
        self::assertStringNotContainsString('gateway->postJson', $source);
    }

    public function test_sync_wp_controller_never_forces_queued_for_project_local_save(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(ArticleEditorSyncController::class, 'syncWp'),
        );

        self::assertStringContainsString('project_local_save', $source);
        self::assertStringContainsString('workspaceOnly', $source);
        self::assertStringContainsString("\$result['queued'] = false", $source);
        self::assertStringContainsString('content_hash', $source);
        self::assertStringContainsString('\$proven', $source);
        self::assertStringContainsString("\$result['close_editor'] = \$proven", $source);
    }

    public function test_frontend_closes_only_after_proven_project_local_save(): void
    {
        $apiPath = dirname(__DIR__, 2).'/resources/js/utils/articleEditorApi.js';
        $api = (string) file_get_contents($apiPath);

        self::assertStringContainsString('closeEditorAfterProjectLocalSave', $api);
        self::assertStringContainsString('project_local_save', $api);
        self::assertStringContainsString('contentHash', $api);
        self::assertStringContainsString('savedAt', $api);
        self::assertStringContainsString('const proven = result?.success !== false', $api);
        self::assertStringContainsString('if (!proven)', $api);
        self::assertStringContainsString('closeEditorAfterProjectLocalSave(', $api);

        $provenBlock = $this->extractBetween($api, 'if (workspaceOnly) {', 'if (result.queued) {');
        self::assertStringContainsString('if (!proven)', $provenBlock);
        self::assertStringContainsString('return;', $provenBlock);
        self::assertStringNotContainsString('closeEditorTabOrRedirectToSyncQueue()', $provenBlock);

        $actions = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
        );
        self::assertMatchesRegularExpression(
            '/\$inContentProject\s*=.*articleIsInContentProject[\s\S]*?\$syncLabel\s*=\s*\$inContentProject/',
            $actions,
        );
        self::assertStringContainsString('page_action_save_close_label', $actions);
        self::assertStringContainsString('data-seo-sync-mode', $actions);
        self::assertStringContainsString('project_local_save', $actions);
    }

    public function test_has_unpublished_changes_ignores_stale_wp_post_id_alone(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleWordPressSyncFlagService::class))->getFileName(),
        );

        self::assertStringContainsString('META_LOCAL_CONTENT_HASH', $source);
        self::assertStringContainsString('META_PUBLISHED_CONTENT_HASH', $source);
        self::assertStringContainsString('function hasUnpublishedChanges', $source);
        self::assertStringContainsString('publishedContentHash', $source);
        self::assertStringContainsString('wp_post_id alone is insufficient', $source);

        $method = $this->methodSource(
            new ReflectionMethod(ArticleWordPressSyncFlagService::class, 'hasUnpublishedChanges'),
        );
        self::assertStringContainsString('hasLocalEditPending', $method);
        self::assertStringContainsString('hash_equals', $method);
    }

    public function test_published_item_with_dirty_local_shows_publish_now(): void
    {
        $clean = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'published',
            'queue_status' => 'published',
            'has_unpublished_changes' => false,
            'article_edit_url' => '/seo/articles/1/edit',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => true,
            'is_improve' => false,
            'is_scheduled' => false,
        ]);
        self::assertFalse($clean['publish_now']);

        $dirty = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'published',
            'queue_status' => 'published',
            'has_unpublished_changes' => true,
            'article_edit_url' => '/seo/articles/1/edit',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => true,
            'is_improve' => false,
            'is_scheduled' => false,
        ]);
        self::assertTrue($dirty['publish_now']);
    }

    public function test_publish_now_allows_published_to_waiting_for_wp_update(): void
    {
        $guard = new ContentProjectPublishTransitionGuard();
        $guard->assertCanTransition(
            ContentProjectPublishQueueStatus::Published,
            ContentProjectPublishQueueStatus::Waiting,
        );

        $enqueue = $this->methodSource(
            new ReflectionMethod(ContentProjectPublishingQueueService::class, 'enqueueExplicitPublish'),
        );
        self::assertStringNotContainsString(
            'ContentProjectPublishQueueStatus::Published'."\n".'                || $from === ContentProjectPublishQueueStatus::Processing',
            $enqueue,
        );
        self::assertStringContainsString('ContentProjectPublishQueueStatus::Processing', $enqueue);
        self::assertStringContainsString('update existing WP post', $enqueue);
    }

    public function test_wordpress_publisher_requests_delivery_for_existing_wp_post(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(WordPressPublisher::class))->getFileName(),
        );

        self::assertStringContainsString('Publish update delivery requested', $source);
        self::assertStringContainsString('deliveryRequested: true', $source);
        self::assertStringContainsString('Updated existing WordPress post', $source);
        self::assertStringNotContainsString('Already published (wp_post_id present)', $source);
    }

    public function test_publish_handler_defers_hash_clear_until_wp_success_on_delivery(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(ProcessScheduledProjectItemPublishHandler::class, 'processPublish'),
        );

        self::assertStringContainsString('deliveryRequested', $source);
        self::assertStringContainsString('do NOT clear has_unpublished_changes', $source);

        $deliveryBlock = $this->extractBetween(
            $source,
            'if ($publishResult->deliveryRequested) {',
            'return ContentProjectActionResult::ok(',
        );
        self::assertStringNotContainsString('rememberPublishedContentHash', $deliveryBlock);
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
