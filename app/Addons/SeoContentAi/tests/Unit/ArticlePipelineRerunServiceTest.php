<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArticlePipelineRerunService;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArticlePipelineRerunServiceTest extends TestCase
{
    public function test_queue_delegates_to_command_bus_step_command(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticlePipelineRerunService::class))->getFileName(),
        );

        self::assertStringContainsString('RerunProjectItemStepCommand', $source);
        self::assertStringContainsString('commandBus->dispatch', $source);
        self::assertStringContainsString('syncExecution: true', $source);
        self::assertStringNotContainsString('RerunArticlePipelineJob', $source);
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/Jobs/RerunArticlePipelineJob.php');
    }

    public function test_edit_article_keeps_adapter_but_ui_removed_pipeline_rerun_menu(): void
    {
        $editPhp = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        self::assertStringContainsString('queueArticlePipelineRerun', $editPhp);
        self::assertStringContainsString('ArticlePipelineRerunService', $editPhp);
        self::assertStringContainsString('ArticleAiHistoryApplicationService', $editPhp);

        $actionsBlade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
        );
        self::assertStringNotContainsString('data-seo-pipeline-rerun', $actionsBlade);
        self::assertStringContainsString('article_ai_history.menu', $actionsBlade);

        $editBlade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/edit-article.blade.php',
        );
        self::assertStringNotContainsString('open-article-pipeline-rerun-modal', $editBlade);
        self::assertStringContainsString('aiHistoryPendingBanner', $editBlade);
    }

    public function test_constants_and_block_message_stable(): void
    {
        self::assertSame('outline', ArticlePipelineRerunService::FROM_OUTLINE);
        self::assertSame('article', ArticlePipelineRerunService::FROM_ARTICLE);
        self::assertSame(
            ArticlePipelineRerunService::BLOCK_NO_PROJECT,
            'Bài viết phải được gắn vào Content Project trước khi chạy lại quy trình.',
        );
    }

    public function test_step_command_name(): void
    {
        $cmd = new RerunProjectItemStepCommand(1, [2], \App\Addons\SeoContentAi\Enums\ContentProjectRerunFromStep::Outline);
        self::assertSame('content_project.rerun_step', $cmd->name());
    }
}
