<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Jobs\RerunArticlePipelineJob;
use App\Addons\SeoContentAi\Services\ArticlePipelineRerunService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepCatalogService;
use App\Addons\SeoContentAi\Services\TaskWorkflowTestRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ArticlePipelineRerunServiceTest extends TestCase
{
    public function test_debug_import_markdown_removed_from_editor_surfaces(): void
    {
        $actions = file_get_contents(dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php');
        $editBlade = file_get_contents(dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/edit-article.blade.php');
        $editPhp = file_get_contents(dirname(__DIR__, 2).'/Filament/Resources/ArticleResource/Pages/EditArticle.php');
        $sidebar = file_get_contents(dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/partials/publish-sidebar.blade.php');
        $headerJs = file_get_contents(dirname(__DIR__, 2).'/resources/js/utils/articleEditorHeaderActions.js');

        self::assertIsString($actions);
        self::assertIsString($editBlade);
        self::assertIsString($editPhp);
        self::assertIsString($sidebar);
        self::assertIsString($headerJs);

        self::assertStringNotContainsString('debug-import-markdown', $actions);
        self::assertStringNotContainsString('Debug import Markdown', $actions);
        self::assertStringNotContainsString('open-debug-markdown-import', $editBlade);
        self::assertStringNotContainsString('importMarkdownDebug', $editPhp);
        self::assertStringNotContainsString('submitMarkdownImportFromSidebar', $editPhp);
        self::assertStringNotContainsString('Import nhanh', $sidebar);
        self::assertStringNotContainsString('data-seo-debug-md-import', $headerJs);

        self::assertStringContainsString('importMarkdownFaqDebug', $editPhp);
        self::assertStringContainsString('pipeline-rerun', $actions);
        self::assertStringContainsString('queueArticlePipelineRerun', $editPhp);
        self::assertStringContainsString('open-article-pipeline-rerun-modal', $editBlade);
    }

    public function test_normalize_from_step_maps_article_and_outline(): void
    {
        $service = $this->newServiceWithoutConstructor();

        self::assertSame('outline', $service->normalizeFromStep('outline'));
        self::assertSame('article', $service->normalizeFromStep('article'));
        self::assertSame('article', $service->normalizeFromStep('content'));
        self::assertSame('outline', $service->normalizeFromStep('unknown'));
    }

    public function test_block_message_constant_matches_requirement(): void
    {
        self::assertSame(
            'Bài viết phải được gắn vào Content Project trước khi chạy lại quy trình.',
            ArticlePipelineRerunService::BLOCK_NO_PROJECT,
        );
    }

    public function test_lock_key_includes_article_and_from(): void
    {
        $service = $this->newServiceWithoutConstructor();

        self::assertSame(
            'seo:article-pipeline-rerun:42:outline',
            $service->lockKey(42, 'outline'),
        );
        self::assertSame(
            'seo:article-pipeline-rerun:42:article',
            $service->lockKey(42, 'content'),
        );
    }

    public function test_job_is_unique_per_article_and_from(): void
    {
        $job = new RerunArticlePipelineJob(10, 55, 'outline', 1);

        self::assertSame('article-pipeline-rerun:55:outline', $job->uniqueId());
        self::assertTrue(is_a(RerunArticlePipelineJob::class, \Illuminate\Contracts\Queue\ShouldBeUnique::class, true));
        self::assertTrue(is_a(RerunArticlePipelineJob::class, \Illuminate\Contracts\Queue\ShouldQueue::class, true));
    }

    public function test_job_uses_revision_rollback_and_run_from_node(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/Jobs/RerunArticlePipelineJob.php');
        self::assertIsString($source);
        self::assertStringContainsString('runFromNodeId', $source);
        self::assertStringContainsString('captureAfterSave', $source);
        self::assertStringContainsString('restoreRevisionToArticle', $source);
        self::assertStringNotContainsString('SeoProjectWorkflowStepRetryService', $source);
        $serviceSource = file_get_contents(dirname(__DIR__, 2).'/Services/ArticlePipelineRerunService.php');
        self::assertIsString($serviceSource);
        self::assertStringContainsString("'run_type' => 'rerun'", $serviceSource);
        self::assertStringContainsString("'rerun_from_step'", $serviceSource);
        self::assertStringContainsString("'source_run_id'", $serviceSource);
    }

    public function test_service_does_not_create_run_when_blocked_path_documented(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/Services/ArticlePipelineRerunService.php');
        self::assertIsString($source);
        self::assertStringContainsString('BLOCK_NO_PROJECT', $source);
        self::assertStringContainsString('resolveProjectTask', $source);
        self::assertStringContainsString('RerunArticlePipelineJob::dispatchSync', $source);
        // Block returns before startRun when no task.
        $blockPos = strpos($source, 'BLOCK_NO_PROJECT');
        $startPos = strpos($source, 'startRun');
        self::assertNotFalse($blockPos);
        self::assertNotFalse($startPos);
        self::assertLessThan($startPos, (int) $blockPos);
        self::assertStringNotContainsString('RerunArticlePipelineJob::dispatch(', $source);
    }

    public function test_catalog_exposes_from_kind_helpers(): void
    {
        $ref = new ReflectionClass(SeoProjectWorkflowStepCatalogService::class);
        self::assertTrue($ref->hasMethod('firstPromptNodeIdForKind'));
        self::assertTrue($ref->hasMethod('promptNodeIdsFromKindInclusive'));
        self::assertTrue($ref->hasMethod('normalizeRerunKind'));
    }

    public function test_runner_exposes_run_from_node_id(): void
    {
        $method = new ReflectionMethod(TaskWorkflowTestRunner::class, 'runFromNodeId');
        self::assertTrue($method->isPublic());
        self::assertSame(4, $method->getNumberOfParameters());
    }

    public function test_detect_kind_treats_viet_bai_theo_dan_y_as_content_not_outline(): void
    {
        $ref = new ReflectionClass(SeoProjectWorkflowStepCatalogService::class);
        $service = $ref->newInstanceWithoutConstructor();
        $detect = $ref->getMethod('detectKind');
        $detect->setAccessible(true);

        self::assertSame('content', $detect->invoke($service, ['title' => 'Viết bài theo dàn ý'], null));
        self::assertSame('outline', $detect->invoke($service, ['title' => 'Tạo dàn ý SEO'], null));
    }

    public function test_editor_watches_rerun_and_notifies_on_complete(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/Filament/Resources/ArticleResource/Pages/EditArticle.php');
        self::assertIsString($source);
        self::assertStringContainsString('pipelineRerunWatching', $source);
        self::assertStringContainsString('Chạy lại quy trình thành công', $source);
        self::assertStringContainsString('seo-article-pipeline-rerun-completed', $source);
        self::assertStringContainsString('abandonStaleActiveRuns', file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticlePipelineRerunService.php',
        ) ?: '');
        $blade = file_get_contents(dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/edit-article.blade.php');
        self::assertIsString($blade);
        self::assertStringNotContainsString('wire:poll.3s="refreshPipelineRerunStatus"', $blade);
        self::assertStringNotContainsString('wire:poll.5s="refreshPipelineRerunStatus"', $blade);
    }

    public function test_editor_clears_local_draft_on_rerun_completed_event(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/article-editor.jsx');
        self::assertIsString($source);
        self::assertStringContainsString('seo-article-pipeline-rerun-completed', $source);
        self::assertStringContainsString('clearArticleLocalState', $source);
    }

    public function test_prepare_operation_salts_idempotency_with_run_id(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/Services/SeoProjectRunItemService.php');
        self::assertIsString($source);
        self::assertStringContainsString('#run:%d', $source);
        self::assertStringContainsString('bắt buộc gắn run_id', $source);
    }

    public function test_runner_seeds_outline_into_node_outputs_for_content_rerun(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/Services/TaskWorkflowTestRunner.php');
        self::assertIsString($source);
        self::assertStringContainsString('hydrateSkippedNodeWithOutline', $source);
        self::assertStringContainsString('direct_publish_outline_markdown', $source);
        self::assertStringContainsString("variables['input'] = \$input", $source);
    }

    public function test_modal_copy_matches_requirement(): void
    {
        $vi = file_get_contents(dirname(__DIR__, 2).'/lang/vi/filament.php');
        self::assertIsString($vi);
        self::assertStringContainsString("'modal_title' => 'Chạy lại quy trình bài viết'", $vi);
        self::assertStringContainsString('Từ dàn ý', $vi);
        self::assertStringContainsString('Từ bài viết', $vi);
        self::assertStringContainsString("'queue' => 'Chạy ngay'", $vi);
        self::assertStringContainsString('lịch sử chỉnh sửa', $vi);
    }

    private function newServiceWithoutConstructor(): ArticlePipelineRerunService
    {
        $ref = new ReflectionClass(ArticlePipelineRerunService::class);

        return $ref->newInstanceWithoutConstructor();
    }
}
