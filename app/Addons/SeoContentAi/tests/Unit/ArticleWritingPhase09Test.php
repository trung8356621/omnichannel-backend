<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\WorkflowExecutionRole;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Services\ArticleGenerationInputResolver;
use App\Addons\SeoContentAi\Services\ArticleOutlineResolver;
use App\Addons\SeoContentAi\Services\ArticleWritingExecutionService;
use App\Addons\SeoContentAi\Services\ArticleWritingLegacyRewriteAdapter;
use App\Addons\SeoContentAi\Services\WorkflowExistingAiOutputService;
use App\Addons\SeoContentAi\Services\WorkflowRoles\WorkflowRoleMigrationSuggester;
use PHPUnit\Framework\TestCase;

final class ArticleWritingPhase09Test extends TestCase
{
    public function test_runner_has_no_title_heuristic_for_outline_capture(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringNotContainsString("str_contains(\$title, 'dàn ý')", $src);
        self::assertStringNotContainsString("str_contains(\$haystack, 'dàn ý')", $src);
        self::assertStringContainsString('isOutlineExecutionNode', $src);
        self::assertStringContainsString('WorkflowExecutionRole::ArticleOutlineGenerate', $src);
    }

    public function test_outline_producer_ignores_title_and_latest_content_artifact(): void
    {
        $outline = \Mockery::mock(ArticleOutlineResolver::class);
        $resolver = new ArticleGenerationInputResolver($outline);
        self::assertFalse($resolver->isOutlineProducerStep([
            'title' => 'Tạo dàn ý SEO',
            'prompt_name' => 'Outline magic',
            'hook_key' => '',
            'output' => ArticleGenerationInputResolver::OUTLINE_START."\nx\n"
                .ArticleGenerationInputResolver::OUTLINE_END."\n"
                .ArticleGenerationInputResolver::VOCABULARY_START."\ny\n"
                .ArticleGenerationInputResolver::VOCABULARY_END,
        ]));
        self::assertTrue($resolver->isOutlineProducerStep([
            'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
            'output' => 'x',
        ]));
        self::assertFalse($resolver->isOutlineProducerStep([
            'title' => 'Viết bài theo dàn ý',
            'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
            'hook_key' => 'article.content.generate',
            'output' => ArticleGenerationInputResolver::OUTLINE_START."\nx\n"
                .ArticleGenerationInputResolver::OUTLINE_END."\n"
                .ArticleGenerationInputResolver::VOCABULARY_START."\ny\n"
                .ArticleGenerationInputResolver::VOCABULARY_END,
        ]));
    }

    public function test_existing_ai_output_uses_role_not_prompt_name(): void
    {
        $svc = new WorkflowExistingAiOutputService;
        $prompt = (new SeoPrompt)->forceFill(['name' => 'Dàn ý bài viết']);
        self::assertNull($svc->outputType([], $prompt));
        self::assertSame(
            WorkflowExistingAiOutputService::TYPE_OUTLINE,
            $svc->outputType([
                'data' => ['execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value],
            ], $prompt),
        );
        self::assertSame(
            WorkflowExistingAiOutputService::TYPE_CONTENT,
            $svc->outputType([
                'data' => ['execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value],
            ], $prompt),
        );
    }

    public function test_no_runtime_reads_rewrite_article_task_id(): void
    {
        $roots = [
            dirname(__DIR__, 2).'/Services/CreateArticlesFromTaskService.php',
            dirname(__DIR__, 2).'/Services/ArticleWritingExecutionService.php',
            dirname(__DIR__, 2).'/Services/ArticleWritingLegacyRewriteAdapter.php',
            dirname(__DIR__, 2).'/Services/SeoProjectWorkflowStepCatalogService.php',
            dirname(__DIR__, 2).'/Services/TaskWorkflowTestRunner.php',
        ];
        foreach ($roots as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('getRewriteArticleTaskId(', $src, basename($file));
        }
    }

    public function test_legacy_adapter_only_delegates(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleWritingLegacyRewriteAdapter.php',
        );
        self::assertStringNotContainsString('getPublishArticleTaskId', $src);
        self::assertStringNotContainsString('getRewriteArticleTaskId', $src);
        self::assertStringNotContainsString('SeoTask::', $src);
        self::assertStringContainsString('ArticleWritingExecutionService', $src);
        self::assertLessThan(160, substr_count($src, "\n"));
    }

    public function test_rewrite_hook_remaps_to_generate(): void
    {
        $adapter = new ArticleWritingLegacyRewriteAdapter(
            new \App\Addons\SeoContentAi\Services\ArticleWritingInputFormatter,
        );
        self::assertSame(
            ArticleWritingLegacyRewriteAdapter::GENERATE_HOOK,
            $adapter->canonicalizeHookKey(ArticleWritingLegacyRewriteAdapter::LEGACY_REWRITE_HOOK),
        );
        self::assertSame(
            'existing_article',
            $adapter->defaultSourceTypeForLegacyRewrite()->value,
        );
    }

    public function test_retry_missing_snapshot_requires_rerun_message(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleWritingExecutionService.php',
        );
        self::assertStringContainsString(
            'Không thể thử lại lần chạy cũ. Hãy chọn «Chạy lại bằng cấu hình hiện tại».',
            $src,
        );
        self::assertStringContainsString('retrySnapshotIsComplete', $src);
        // Retry không fallback live contentNodeId từ context khi snapshot thiếu.
        self::assertDoesNotMatchRegularExpression(
            '/contentNodeId:\s*\$contentNodeId !== \'\' \? \$contentNodeId : \$context->contentNodeId/',
            $src,
        );
    }

    public function test_history_metadata_contract_keys(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleWritingExecutionService.php',
        );
        foreach ([
            "'source_type'",
            "'workflow_id'",
            "'workflow_hash'",
            "'node_id'",
            "'execution_role'",
            "'source_hash'",
            "'retry_or_rerun'",
            "'legacy_adapter'",
        ] as $key) {
            self::assertStringContainsString($key, $src);
        }
    }

    public function test_heuristic_only_in_migration_suggester_for_roles(): void
    {
        self::assertTrue(class_exists(WorkflowRoleMigrationSuggester::class));
        $runner = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/TaskWorkflowTestRunner.php',
        );
        $resolver = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleGenerationInputResolver.php',
        );
        $existing = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/WorkflowExistingAiOutputService.php',
        );
        foreach ([$runner, $resolver, $existing] as $src) {
            self::assertStringNotContainsString("str_contains(\$haystack, 'dàn ý')", $src);
        }
    }

    public function test_execution_service_hook_constant(): void
    {
        self::assertSame('article.content.generate', ArticleWritingExecutionService::HOOK_KEY);
    }
}
