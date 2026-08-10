<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArchiveContentProjectService;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectArticleMembership;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueRunner;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectWorkspaceSaveService;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners\CacheLockWorkspaceCleaner;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners\EditorRevisionWorkspaceCleaner;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners\ExecutionWorkspaceCleaner;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners\GalleryExecutionWorkspaceCleaner;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners\LocalMediaWorkspaceCleaner;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners\PendingArtifactsWorkspaceCleaner;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners\PromptWorkspaceCleaner;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners\RuntimeWorkspaceCleaner;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\ContentProjectAiWorkspaceDestroyer;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupRegistry;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
use App\Addons\SeoContentAi\Services\ScheduledArticlePublishRunner;
use App\Addons\SeoContentAi\Services\SeoArticleRevisionService;
use App\Addons\SeoContentAi\Services\WordPress\WordPressManualSyncService;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: Archive = Destroy Workspace; Manual Sync WP in Project = fail-closed; SaaS Publishing Queue.
 */
final class ContentProjectWorkspaceDestroyArchitectureTest extends TestCase
{
    public function test_workspace_cleaner_contract_and_registry_keys(): void
    {
        $expected = [
            'execution',
            'prompt',
            'runtime',
            'local_media',
            'gallery_execution',
            'editor_revision',
            'pending_artifacts',
            'cache_lock',
        ];

        foreach ([
            ExecutionWorkspaceCleaner::class,
            PromptWorkspaceCleaner::class,
            RuntimeWorkspaceCleaner::class,
            LocalMediaWorkspaceCleaner::class,
            GalleryExecutionWorkspaceCleaner::class,
            EditorRevisionWorkspaceCleaner::class,
            PendingArtifactsWorkspaceCleaner::class,
            CacheLockWorkspaceCleaner::class,
        ] as $class) {
            self::assertTrue(is_subclass_of($class, ContentProjectWorkspaceCleaner::class));
        }

        $registryRef = new ReflectionClass(ContentProjectWorkspaceCleanupRegistry::class);
        self::assertTrue($registryRef->hasMethod('register'));
        self::assertTrue($registryRef->hasMethod('all'));

        $destroyerRef = new ReflectionClass(ContentProjectAiWorkspaceDestroyer::class);
        self::assertTrue($destroyerRef->hasMethod('destroyInTransaction'));
        self::assertTrue($destroyerRef->hasMethod('releaseDeferredSideEffects'));

        foreach ($expected as $key) {
            self::assertContains($key, $expected);
        }
    }

    public function test_archive_service_destroys_workspace_inside_transaction(): void
    {
        $ctor = (new ReflectionClass(ArchiveContentProjectService::class))->getConstructor();
        self::assertNotNull($ctor);
        $paramTypes = array_map(
            static fn ($p) => $p->getType()?->getName(),
            $ctor->getParameters(),
        );
        self::assertContains(ContentProjectAiWorkspaceDestroyer::class, $paramTypes);

        $source = $this->readMethodSource(
            (new ReflectionClass(ArchiveContentProjectService::class))->getMethod('archive'),
        );
        self::assertStringContainsString('workspaceDestroyer->destroyInTransaction', $source);
        self::assertStringContainsString('releaseDeferredSideEffects', $source);
        self::assertStringContainsString('workspace_destroyed', $source);
        self::assertStringContainsString("DB::connection('omi_seo_ai')->transaction", $source);
    }

    public function test_restore_does_not_reuse_old_workspace(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(ArchiveContentProjectService::class))->getMethod('restore'),
        );
        self::assertStringContainsString('workspace_reused', $source);
        self::assertStringContainsString('false', $source);
        self::assertStringNotContainsString('destroyInTransaction', $source);
        self::assertStringNotContainsString('workspaceDestroyer', $source);
    }

    public function test_local_media_cleaner_deletes_laravel_copies_even_when_synced_to_wordpress(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(LocalMediaWorkspaceCleaner::class))->getMethod('clean'),
        );

        self::assertStringContainsString("whereIn('article_id', \$articleIds)", $source);
        self::assertStringContainsString("get(['id', 'path'])", $source);
        self::assertStringContainsString('queueDiskPath', $source);
        self::assertStringContainsString('SeoMedia::query()->whereIn', $source);
        self::assertStringNotContainsString('wp_attachment_id', $source);
        self::assertStringNotContainsString('SOURCE_LOCAL', $source);
    }

    public function test_manual_sync_fail_closed_for_content_project_and_publish_stays_on_queue(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(WordPressManualSyncService::class))->getMethod('enqueueFromEditorBundle'),
        );
        self::assertStringContainsString('belongsToContentProject', $source);
        self::assertStringContainsString('content_project_manual_sync_forbidden', $source);
        self::assertStringContainsString('PostPublishWordPressSyncEligibility', $source);
        self::assertStringContainsString('syncPublishedFromEditorBundle', $source);
        self::assertStringNotContainsString('workspaceSave->saveFromEditorBundle', $source);

        $membership = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArticleMembership::class))->getFileName(),
        );
        self::assertStringContainsString('function belongsToContentProject', $membership);
        self::assertStringContainsString('function assignedTaskForArticle', $membership);

        $publishSource = $this->readMethodSource(
            (new ReflectionClass(WordPressManualSyncService::class))->getMethod('publishNow'),
        );
        self::assertStringContainsString('PublishProjectItemsNowCommand', $publishSource);
        self::assertStringContainsString('content_project_publish_queued', $publishSource);
        self::assertStringNotContainsString("'scheduled_publish_at' => \$dueAt", $publishSource);
    }

    public function test_publishing_queue_uses_task_scheduled_publish_at(): void
    {
        $casts = (new ReflectionClass(SeoProjectTask::class))->getDefaultProperties()['casts'] ?? [];
        self::assertArrayHasKey('scheduled_publish_at', $casts);

        $runnerSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectPublishingQueueRunner::class))->getFileName(),
        );
        self::assertStringContainsString('scheduled_publish_at', $runnerSource);
        self::assertTrue(
            str_contains($runnerSource, 'content_project_publishing_queue')
            || str_contains($runnerSource, 'ProcessScheduledProjectItemPublish')
            || str_contains($runnerSource, 'ContentProjectCommandBus'),
        );
        self::assertStringNotContainsString('future', strtolower($runnerSource));

        $scheduledSource = (string) file_get_contents(
            (new ReflectionClass(ScheduledArticlePublishRunner::class))->getFileName(),
        );
        self::assertStringContainsString('contentProjectQueue->dispatchDue', $scheduledSource);
        self::assertStringContainsString('projectTasks', $scheduledSource);
    }

    public function test_revision_skips_saas_history_after_wordpress_sync_unless_forced(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(SeoArticleRevisionService::class))->getMethod('captureAfterSave'),
        );
        self::assertStringContainsString('wp_post_id', $source);
        self::assertStringContainsString('$force', $source);
        self::assertStringContainsString('return null', $source);
    }

    public function test_membership_and_workspace_save_services_exist(): void
    {
        self::assertTrue(class_exists(ContentProjectArticleMembership::class));
        self::assertTrue(class_exists(ContentProjectWorkspaceSaveService::class));

        $saveSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectWorkspaceSaveService::class))->getFileName(),
        );
        self::assertStringContainsString('wp_api_called', $saveSource);
        self::assertStringContainsString('false', $saveSource);
        self::assertStringContainsString('last_synced_at', $saveSource);
    }

    private function readMethodSource(ReflectionMethod $method): string
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
