<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\ArticleImproveScope;
use App\Addons\SeoContentAi\Enums\WorkflowExecutionRole;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Services\ArticleImproveExecutionService;
use App\Addons\SeoContentAi\Services\ArticleWritingExecutionService;
use App\Addons\SeoContentAi\Enums\ContentProjectRerunFromStep;
use App\Addons\SeoContentAi\Services\PromptOwnership\DefaultImprovePromptInstaller;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepCatalogService;
use App\Addons\SeoContentAi\Services\WorkflowRoles\WorkflowExecutionRoleRegistry;
use App\Addons\SeoContentAi\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use App\Addons\SeoContentAi\Services\WorkflowRoles\WorkflowRoleMigrationSuggester;
use App\Addons\SeoContentAi\Support\ArticleImproveInput;
use PHPUnit\Framework\TestCase;

final class WorkflowExecutionRoleTest extends TestCase
{
    public function test_registry_lists_canonical_roles(): void
    {
        $keys = array_column((new WorkflowExecutionRoleRegistry)->all(), 'key');

        self::assertContains(WorkflowExecutionRole::ArticleOutlineGenerate->value, $keys);
        self::assertContains(WorkflowExecutionRole::ArticleContentGenerate->value, $keys);
        self::assertContains(WorkflowExecutionRole::ArticleContentImprove->value, $keys);
        self::assertContains(WorkflowExecutionRole::ArticleImageGenerate->value, $keys);
    }

    public function test_resolver_finds_node_by_role_not_title(): void
    {
        $task = new SeoTask;
        $task->flow_data = [
            'nodes' => [
                [
                    'id' => 'n_outline',
                    'type' => 'prompt',
                    'title' => 'Viết bài theo dàn ý', // title lừa — role mới đúng
                    'data' => [
                        'promptId' => 1,
                        'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
                    ],
                ],
                [
                    'id' => 'n_content',
                    'type' => 'prompt',
                    'title' => 'Something else',
                    'data' => [
                        'promptId' => 2,
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                    ],
                ],
            ],
            'edges' => [],
        ];

        $resolver = new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry);
        $found = $resolver->findNode($task, WorkflowExecutionRole::ArticleContentGenerate);

        self::assertNotNull($found);
        self::assertSame('n_content', $found['node_id']);
        self::assertSame(
            'n_outline',
            $resolver->requireNodeId($task, WorkflowExecutionRole::ArticleOutlineGenerate),
        );
    }

    public function test_missing_role_throws_clear_message(): void
    {
        $task = new SeoTask;
        $task->id = 99;
        $task->name = 'Publish';
        $task->flow_data = ['nodes' => [], 'edges' => []];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('article.content.generate');

        (new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry))
            ->requireNodeId($task, WorkflowExecutionRole::ArticleContentGenerate);
    }

    public function test_duplicate_unique_role_blocked(): void
    {
        // Không gắn promptId — tránh SeoPrompt::query() khi PHPUnit chưa bootstrap DB.
        $errors = (new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry))->validateFlowData([
            'nodes' => [
                [
                    'id' => 'a',
                    'type' => 'prompt',
                    'data' => ['execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value],
                ],
                [
                    'id' => 'b',
                    'type' => 'prompt',
                    'data' => ['execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value],
                ],
            ],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('trùng', implode(' ', $errors));
    }

    public function test_execution_service_has_no_title_heuristic(): void
    {
        $ref = new \ReflectionClass(ArticleWritingExecutionService::class);
        $ctorParams = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $ref->getConstructor()?->getParameters() ?? [],
        );
        self::assertContains('roleResolver', $ctorParams);

        $source = (string) file_get_contents((string) $ref->getFileName());
        self::assertStringContainsString('requireNodeId', $source);
        self::assertStringNotContainsString('promptNodes[1]', $source);
        // Không resolve node bằng title/haystack heuristic.
        self::assertDoesNotMatchRegularExpression('/str_contains\s*\(\s*\$title\b/', $source);
        self::assertDoesNotMatchRegularExpression('/str_contains\s*\(\s*\$haystack\b/', $source);
    }

    public function test_catalog_detect_kind_has_no_title_heuristic(): void
    {
        $ref = new \ReflectionClass(SeoProjectWorkflowStepCatalogService::class);
        $catalog = $ref->newInstanceWithoutConstructor();
        $resolverProp = $ref->getProperty('roleResolver');
        $resolverProp->setValue(
            $catalog,
            new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry),
        );

        $detectKind = $ref->getMethod('detectKind');
        $misleadingTitle = [
            'title' => 'Viết bài theo dàn ý / Tạo outline',
            'data' => [
                'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
            ],
        ];
        self::assertSame(
            'content',
            $detectKind->invoke($catalog, $misleadingTitle, null),
            'Kind phải theo execution_role, không theo title',
        );

        $noRole = [
            'title' => 'Tạo dàn ý bài viết SEO',
            'data' => [],
        ];
        self::assertSame(
            'prompt',
            $detectKind->invoke($catalog, $noRole, null),
            'Không có role → generic prompt, không đoán outline từ title',
        );
    }

    public function test_migration_suggester_high_hook_only(): void
    {
        $registry = new WorkflowExecutionRoleRegistry;

        self::assertSame(
            WorkflowExecutionRole::ArticleContentGenerate,
            $registry->suggestRoleFromHook('article.content.generate'),
        );
        self::assertSame(
            WorkflowExecutionRole::ArticleOutlineGenerate,
            $registry->suggestRoleFromHook('article.outline.generate'),
        );
        self::assertNull($registry->suggestRoleFromHook(''));
        self::assertNull($registry->suggestRoleFromHook('unknown.hook'));
    }

    public function test_bulk_actions_use_rerun_from_step_enum(): void
    {
        self::assertSame('outline', ContentProjectRerunFromStep::Outline->value);
        self::assertSame(
            ContentProjectRerunFromStep::Outline,
            ContentProjectRerunFromStep::tryFromMixed('regenerate_outline'),
        );

        $js = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/project-run-queue.js',
        );
        self::assertStringContainsString('bulkRerunByAction', $js);
        self::assertStringContainsString('previewBulkRerunByAction', $js);
    }

    public function test_improve_installer_idempotent_contract(): void
    {
        self::assertSame(
            ArticleImproveExecutionService::HOOK_KEY,
            DefaultImprovePromptInstaller::HOOK_KEY,
        );
        self::assertStringContainsString('{{input}}', DefaultImprovePromptInstaller::MARKDOWN);
        self::assertStringContainsString('{{instruction}}', DefaultImprovePromptInstaller::MARKDOWN);
        self::assertStringContainsString('{{tone}}', DefaultImprovePromptInstaller::MARKDOWN);
        self::assertStringNotContainsString('article_length', DefaultImprovePromptInstaller::MARKDOWN);
    }

    public function test_improve_scope_selection_rejected_without_patch_path(): void
    {
        $input = new ArticleImproveInput(
            articleId: 1,
            bodyMarkdown: 'body',
            instruction: 'fix typo',
            scope: ArticleImproveScope::Selection,
            selectedText: 'typo',
        );

        self::assertSame(ArticleImproveScope::Selection, $input->scope);

        $serviceSource = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleImproveExecutionService.php',
        );
        self::assertStringContainsString('chưa hỗ trợ persist an toàn', $serviceSource);
        self::assertStringNotContainsString('getPublishArticleTaskId', $serviceSource);
        // Tránh match nhầm ArticleWritingExecutionResult.
        self::assertDoesNotMatchRegularExpression(
            '/\bArticleWritingExecutionService\b/',
            $serviceSource,
        );
    }

    public function test_create_service_outline_then_article_uses_roles(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/CreateArticlesFromTaskService.php',
        );

        self::assertStringContainsString('runOutlineThenArticleForContext', $source);
        self::assertStringContainsString('ArticleOutlineGenerate', $source);
        self::assertStringContainsString('ArticleContentGenerate', $source);
        self::assertStringContainsString('outline_artifact_hash', $source);
        self::assertStringContainsString('article_source_artifact_hash', $source);
        self::assertStringContainsString('article_blocked', $source);
    }

    public function test_command_and_migration_files_exist(): void
    {
        self::assertFileExists(
            dirname(__DIR__, 2).'/Console/AssignWorkflowExecutionRolesCommand.php',
        );
        self::assertFileExists(
            dirname(__DIR__, 2).'/database/migrations/2026_07_26_140000_install_default_improve_prompt_binding.php',
        );
        self::assertTrue(class_exists(WorkflowRoleMigrationSuggester::class));
        self::assertTrue(class_exists(ArticleWritingExecutionService::class));
    }

    public function test_builder_exposes_execution_role_field(): void
    {
        $jsx = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/ArticleFlowBuilder.jsx',
        );

        self::assertStringContainsString('execution_role', $jsx);
        self::assertStringContainsString('Vai trò thực thi', $jsx);
        self::assertStringContainsString('__SEO_WORKFLOW_ROLES__', $jsx);
    }
}
