<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\ArticleImproveScope;
use App\Addons\SeoContentAi\Services\ArticleImproveExecutionService;
use App\Addons\SeoContentAi\Services\PromptOwnership\DefaultImprovePromptInstaller;
use App\Addons\SeoContentAi\Support\ArticleImproveInput;
use PHPUnit\Framework\TestCase;

final class ArticleImproveIsolationPhase07Test extends TestCase
{
    public function test_improve_hook_is_not_generate(): void
    {
        self::assertSame('article.content.improve', ArticleImproveExecutionService::HOOK_KEY);
        self::assertNotSame('article.content.generate', ArticleImproveExecutionService::HOOK_KEY);
    }

    public function test_default_installer_does_not_overwrite_existing_binding_logic(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/PromptOwnership/DefaultImprovePromptInstaller.php',
        );

        // Chỉ bind khi KEY chưa có — không ghi đè operator.
        self::assertStringContainsString('if (! isset($bindings[self::HOOK_KEY]))', $source);
        self::assertStringContainsString('savePromptHookBindings', $source);
    }

    public function test_improve_input_supports_scope_metadata(): void
    {
        $input = new ArticleImproveInput(
            articleId: 10,
            bodyMarkdown: '# Body',
            instruction: 'Rút gọn đoạn 2',
            scope: ArticleImproveScope::Article,
            expectedUpdatedAt: '2026-07-26T00:00:00+00:00',
        );

        self::assertSame(ArticleImproveScope::Article, $input->scope);
        self::assertNull($input->selectedText);
    }

    public function test_improve_markdown_has_no_article_length(): void
    {
        self::assertStringNotContainsString(
            'article_length',
            DefaultImprovePromptInstaller::MARKDOWN,
        );
    }
}
