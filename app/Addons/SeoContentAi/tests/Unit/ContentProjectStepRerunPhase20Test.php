<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\ContentProjectStepRerunMode;
use App\Addons\SeoContentAi\Services\ArticleLastContentChangeResolver;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectStepRerunService;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectStepSourceValidator;
use App\Addons\SeoContentAi\Services\ContentProjectBulkRerunService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepCatalogService;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStepDescriptor;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStepRerunRequest;
use PHPUnit\Framework\TestCase;

final class ContentProjectStepRerunPhase20Test extends TestCase
{
    public function test_step_descriptor_shape_and_no_title_heuristic_in_detect_kind(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/SeoProjectWorkflowStepCatalogService.php',
        );
        self::assertStringContainsString('listStepDescriptors', $src);
        self::assertStringContainsString('ContentProjectStepDescriptor', $src);
        self::assertStringContainsString('kindFromHookKey', $src);
        self::assertStringNotContainsString('str_contains($haystack', $src);
        self::assertStringNotContainsString('mb_stripos($title', $src);

        $descriptor = new ContentProjectStepDescriptor(
            nodeId: 'n1',
            executionRole: 'article.content.generate',
            postType: null,
            hookKey: 'article.content.generate',
            label: 'Chạy lại bài viết',
            kind: 'content',
            sequence: 2,
            rerunnable: true,
            sourceRequirements: ['outline'],
            downstreamNodeIds: [],
        );
        $arr = $descriptor->toArray();
        self::assertSame('n1', $arr['node_id']);
        self::assertSame(['outline'], $arr['source_requirements']);
        self::assertTrue($arr['rerunnable']);
    }

    public function test_rerun_request_defaults_single_step(): void
    {
        $req = ContentProjectStepRerunRequest::fromArray([
            'project_run_id' => 10,
            'project_task_id' => 20,
            'target_node_id' => 'outline-1',
        ]);
        self::assertSame(ContentProjectStepRerunMode::SingleStep, $req->mode);
        self::assertSame('outline-1', $req->targetNodeId);
    }

    public function test_rerun_service_creates_append_only_action_prefix(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ContentProject/ContentProjectStepRerunService.php',
        );
        self::assertStringContainsString("execution_type' => 'rerun'", $src);
        self::assertStringContainsString('step:rr:', $src);
        self::assertStringContainsString('uses_current_workflow', $src);
        self::assertStringContainsString('executePreparedStepItem', $src);
        self::assertStringNotContainsString('prepareStepRunItem', $src);
        self::assertTrue(class_exists(ContentProjectStepRerunService::class));
    }

    public function test_retry_service_keeps_mutate_path_separate(): void
    {
        $retry = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/SeoProjectWorkflowStepRetryService.php',
        );
        self::assertStringContainsString('function prepareStepRunItem', $retry);
        self::assertStringContainsString('function executePreparedStepItem', $retry);
        self::assertStringContainsString("'retry_mode' => 'workflow_step'", $retry);
    }

    public function test_bulk_delegates_to_step_rerun_service(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ContentProjectBulkRerunService.php',
        );
        self::assertStringContainsString('ContentProjectStepRerunService', $src);
        self::assertStringContainsString('executeBulkSerial', $src);
        self::assertStringNotContainsString('retryService->enqueueBulk', $src);
        self::assertSame('regenerate_outline', ContentProjectBulkRerunService::ACTION_OUTLINE);
    }

    public function test_source_validator_article_requires_outline(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ContentProject/ContentProjectStepSourceValidator.php',
        );
        self::assertStringContainsString('requireOutline', $src);
        self::assertStringContainsString('chưa có dàn ý hợp lệ', $src);
        self::assertTrue(class_exists(ContentProjectStepSourceValidator::class));
    }

    public function test_last_content_change_resolver_ignores_updated_at(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleLastContentChangeResolver.php',
        );
        self::assertStringContainsString('last_manual_saved_at', $src);
        self::assertStringContainsString('last_synced_at', $src);
        self::assertStringContainsString('last_ai_content_at', $src);
        // Docblock được phép nhắc updated_at; không được đọc field đó.
        self::assertStringNotContainsString('->updated_at', $src);
        self::assertStringNotContainsString("['updated_at']", $src);
        self::assertStringNotContainsString('updated_at ??', $src);
        self::assertTrue(class_exists(ArticleLastContentChangeResolver::class));
    }

    public function test_view_run_wires_rerun_not_rerun_all(): void
    {
        $page = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php',
        );
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/resources/seo-project-resource/pages/view-project-run.blade.php',
        );
        $js = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/project-run-queue.js',
        );

        self::assertStringContainsString('ContentProjectStepRerunService', $page);
        self::assertStringContainsString('canRerunAllItems(): bool', $page);
        self::assertStringContainsString('return false;', $page);
        self::assertStringContainsString('getGenericPickerSteps', $page);
        self::assertStringContainsString('ArticleLastContentChangeResolver', $page);
        self::assertStringContainsString('Chạy lại bước...', $blade);
        self::assertStringContainsString('openGenericStepPicker', $js);
        self::assertStringNotContainsString('window.prompt(', $js);
        self::assertStringNotContainsString('Chạy lại toàn bộ', $blade);
    }

    public function test_catalog_service_class_exists(): void
    {
        self::assertTrue(class_exists(SeoProjectWorkflowStepCatalogService::class));
    }
}
