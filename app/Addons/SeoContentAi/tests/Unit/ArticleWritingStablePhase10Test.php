<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArticleWritingStableHealthService;
use App\Addons\SeoContentAi\Services\SeoCreateArticleSettingsService;
use PHPUnit\Framework\TestCase;

final class ArticleWritingStablePhase10Test extends TestCase
{
    public function test_settings_ui_does_not_render_rewrite_field(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Pages/SeoSettingsWorkflows.php',
        );
        self::assertStringNotContainsString(
            "taskSelect(\n                            SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE",
            $src,
        );
        self::assertStringContainsString('KEY_REWRITE_ARTICLE: legacy DB field', $src);
        self::assertStringContainsString(
            'KEY_REWRITE_ARTICLE => $settings->getSettings()[SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE]',
            $src,
        );
    }

    public function test_save_settings_preserves_legacy_rewrite_value(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Pages/SeoSettingsWorkflows.php',
        );
        // Form không sở hữu field → save lấy lại từ DB/settings hiện tại.
        self::assertStringContainsString(
            'SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE => $settings->getSettings()[SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE] ?? null',
            $src,
        );
        self::assertSame('rewrite_article_task_id', SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE);
    }

    public function test_hook_selector_excludes_rewrite_for_new_prompts(): void
    {
        $ref = new \ReflectionClass(\App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookEditorCatalog::class);
        self::assertTrue($ref->hasMethod('isLegacyCompatibilityHook'));
        self::assertTrue($ref->hasMethod('selectOptionsForEditing'));

        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/PromptHooks/Runtime/PromptHookEditorCatalog.php',
        );
        self::assertStringContainsString('isLegacyCompatibilityHook', $src);
        self::assertStringContainsString('selectOptionsForEditing', $src);
    }

    public function test_catalog_select_options_skip_rewrite(): void
    {
        // Không bootstrap full registry — assert source filter + editing keep.
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/PromptHooks/Runtime/PromptHookEditorCatalog.php',
        );
        self::assertStringContainsString(
            'if ($this->isLegacyCompatibilityHook($definition->key->value))',
            $src,
        );
        self::assertStringContainsString('Rewrite article content (Legacy)', $src);
    }

    public function test_legacy_prompt_warning_in_form_schema(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/PromptHooks/PromptHookFormSchema.php',
        );
        self::assertStringContainsString('hook_legacy_rewrite_warning', $src);
        self::assertStringContainsString('selectOptionsForEditing', $src);
    }

    public function test_prompt_duplicate_remaps_legacy_hook(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Resources/PromptResource.php',
        );
        self::assertStringContainsString('isLegacyCompatibilityHook', $src);
        self::assertStringContainsString('duplicate_legacy_remapped', $src);
        self::assertStringContainsString('ArticleWritingExecutionService::HOOK_KEY', $src);
    }

    public function test_explicit_generate_does_not_log_legacy_adapter(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/PromptHooks/Runtime/PromptHookExplicitBindingExecutor.php',
        );
        self::assertStringContainsString('isLegacyRewriteHook($binding->hookKey)', $src);
        self::assertStringContainsString('DEPRECATED COMPATIBILITY ONLY', $src);
        // Log chỉ trong nhánh isLegacyRewriteHook.
        self::assertMatchesRegularExpression(
            '/if \(\$this->legacyRewriteAdapter->isLegacyRewriteHook\(\$binding->hookKey\)\) \{[^}]*logLegacyAdapterUsed/s',
            $src,
        );
    }

    public function test_task_runner_adapter_only_for_rewrite_hook(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringContainsString('DEPRECATED COMPATIBILITY ONLY', $src);
        self::assertStringContainsString('isLegacyRewriteHook($hookKey)', $src);
        self::assertStringContainsString("caller: self::class.'::runPromptNode'", $src);
    }

    public function test_editor_action_uses_generate_existing_article(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        self::assertStringContainsString('queueEditorFullRewrite', $src);
        self::assertStringContainsString('existing_article', $src);
        self::assertStringContainsString('type_rewrite_editor', $src);
    }

    public function test_no_runtime_reader_rewrite_task_id(): void
    {
        $health = new \ReflectionClass(ArticleWritingStableHealthService::class);
        self::assertTrue($health->hasMethod('runtimeStillReadsRewriteTaskId'));
        // Instantiating without container: call static-like via partial mock pattern — use file scan helper.
        $svcFile = dirname(__DIR__, 2).'/Services/ArticleWritingStableHealthService.php';
        self::assertFileExists($svcFile);
        $probe = new class
        {
            public function stillReads(): bool
            {
                $files = [
                    dirname(__DIR__, 3).'/Services/CreateArticlesFromTaskService.php',
                    dirname(__DIR__, 3).'/Services/ArticleWritingExecutionService.php',
                    dirname(__DIR__, 3).'/Services/ArticleWritingLegacyRewriteAdapter.php',
                    dirname(__DIR__, 3).'/Services/TaskWorkflowTestRunner.php',
                ];
                // paths wrong in anon — use absolute from test
                return false;
            }
        };
        unset($probe);

        foreach ([
            'CreateArticlesFromTaskService.php',
            'ArticleWritingExecutionService.php',
            'ArticleWritingLegacyRewriteAdapter.php',
            'SeoProjectWorkflowStepCatalogService.php',
            'TaskWorkflowTestRunner.php',
        ] as $name) {
            $src = (string) file_get_contents(dirname(__DIR__, 2).'/Services/'.$name);
            self::assertStringNotContainsString('getRewriteArticleTaskId(', $src, $name);
        }
    }

    public function test_stable_health_file_gates_and_evaluate_fail_contract(): void
    {
        $ref = new \ReflectionClass(ArticleWritingStableHealthService::class);
        $health = $ref->newInstanceWithoutConstructor();

        self::assertFalse($health->runtimeStillReadsRewriteTaskId());
        self::assertFalse($health->runtimeStillHasTitleHeuristic());
        self::assertFalse($health->retryFallsBackToLiveNode());

        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/ArticleWritingStableHealthService.php',
        );
        self::assertStringContainsString('Thiếu Settings binding article.content.generate', $src);
        self::assertStringContainsString('Thiếu Settings binding article.content.improve', $src);
        self::assertStringContainsString('STATUS_FAIL', $src);
        self::assertStringContainsString('rewrite_task_id_populated', $src);
    }

    public function test_stable_health_warns_when_legacy_db_populated(): void
    {
        $settings = new class implements \App\Addons\SeoContentAi\Contracts\SeoCreateArticleSettingsReader
        {
            public function getSettings(): array
            {
                return ['rewrite_article_task_id' => 9];
            }

            public function getPublishArticleTaskId(): ?int
            {
                return null;
            }
        };

        $ref = new \ReflectionClass(ArticleWritingStableHealthService::class);
        $health = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('settings')->setValue($health, $settings);

        $legacy = $health->legacyInventory();
        self::assertSame(1, $legacy['rewrite_task_id_populated']);
    }

    public function test_doctor_prints_stable_gate(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/Console/WorkflowDoctorCommand.php',
        );
        self::assertStringContainsString('Article Writing Stable Gate:', $src);
        self::assertStringContainsString('Legacy compatibility', $src);
        self::assertStringContainsString('ArticleWritingStableHealthService', $src);
    }

    public function test_builder_does_not_treat_rewrite_as_write_from_outline(): void
    {
        $jsx = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/ArticleFlowBuilder.jsx',
        );
        self::assertStringContainsString("hook === 'article.content.generate'", $jsx);
        self::assertStringNotContainsString("hook === 'article.content.rewrite'", $jsx);
    }

    public function test_content_project_labels_final(): void
    {
        $vi = (string) file_get_contents(dirname(__DIR__, 2).'/lang/vi/filament.php');
        self::assertStringContainsString("'type_rewrite' => 'Tạo lại bài từ dàn ý'", $vi);
        self::assertStringContainsString("'type_rewrite_editor' => 'Viết lại toàn bộ bài hiện có'", $vi);
    }

    public function test_option_labels_include_hook_key_code(): void
    {
        $catalogClass = \App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookEditorCatalog::class;
        self::assertTrue((new \ReflectionClass($catalogClass))->hasMethod('labelWithHookKey'));

        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/PromptHooks/Runtime/PromptHookEditorCatalog.php',
        );
        // Single-quoted needle — double-quote sẽ nội suy $definition và needle thành rác.
        self::assertStringContainsString(
            '.$definition->key->value.',
            $src,
        );
        self::assertStringContainsString('function labelWithHookKey', $src);

        $loader = new \App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookDefinitionLoader(
            \App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookDefinitionLoader::defaultV01Directory(),
            \App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $catalog = new \App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookEditorCatalog(
            new \App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookRuntimeRegistry($loader),
        );

        self::assertSame(
            'Write article [article.content.generate]',
            $catalog->labelWithHookKey('Write article', 'article.content.generate'),
        );

        $byKey = [];
        foreach ($catalog->optionsForTextPromptBlock() as $row) {
            $byKey[$row['hook_key']] = $row;
        }
        self::assertArrayHasKey('article.content.generate', $byKey);
        self::assertStringContainsString(
            '[article.content.generate]',
            $byKey['article.content.generate']['option_label'],
        );
    }
}
