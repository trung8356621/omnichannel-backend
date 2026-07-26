<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookCompositionPreviewService;
use App\Addons\SeoContentAi\Services\PromptOwnership\PromptCompositionSummaryPresenter;
use App\Addons\SeoContentAi\Services\PromptOwnership\PromptHookSummaryService;
use Mockery;
use PHPUnit\Framework\TestCase;

final class PromptCompositionSummaryPresenterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_summary_does_not_call_full_compose(): void
    {
        $summary = Mockery::mock(PromptHookSummaryService::class);
        $summary->shouldReceive('summarize')
            ->twice()
            ->andReturn([
                'hook_key' => 'article.faq.generate',
                'hook_version' => '0.1.0',
                'source_path' => 'resources/prompt-hooks/v01/article.faq.generate@0.1.0.json',
                'content_mode' => 'legacy_prompt_content',
                'items' => ['Output contract', 'Runtime variables'],
                'pipeline' => ['Prompt Markdown', 'Hook Template', 'Output Contract', 'Final Prompt'],
            ]);

        $compose = Mockery::mock(PromptHookCompositionPreviewService::class);
        $compose->shouldReceive('preview')->never();
        $compose->shouldReceive('formatPreviewHtml')->never();

        $html = (new PromptCompositionSummaryPresenter($summary, $compose))
            ->renderHtml('article.faq.generate', '0.1.0', "# Role\nHello", [], false)
            ->toHtml();

        self::assertStringContainsString('Runtime compose', $html);
        self::assertStringContainsString('Output contract', $html);
        self::assertStringContainsString('Hello', $html);
        self::assertStringNotContainsString('MARKDOWN ARTICLE OUTPUT CONTRACT', $html);
    }

    public function test_expanded_calls_full_compose(): void
    {
        $summary = Mockery::mock(PromptHookSummaryService::class);
        $summary->shouldReceive('summarize')->once()->andReturn([
            'hook_key' => 'x',
            'hook_version' => '0.1.0',
            'source_path' => '',
            'content_mode' => 'legacy_prompt_content',
            'items' => [],
            'pipeline' => ['Prompt Markdown', 'Final Prompt'],
        ]);

        $compose = Mockery::mock(PromptHookCompositionPreviewService::class);
        $compose->shouldReceive('preview')->once()->andReturn([
            'content_mode' => 'legacy_prompt_content',
            'final_prompt' => 'FULL MERGED',
            'segments' => [],
            'unused_markdown' => false,
            'markdown_preserved' => true,
        ]);
        $compose->shouldReceive('formatPreviewHtml')->once()->andReturn('<pre>FULL MERGED</pre>');

        $html = (new PromptCompositionSummaryPresenter($summary, $compose))
            ->renderHtml('x', '0.1.0', 'md', [], true)
            ->toHtml();

        self::assertStringContainsString('FULL MERGED', $html);
    }

    public function test_no_hook_message(): void
    {
        $summary = Mockery::mock(PromptHookSummaryService::class);
        $summary->shouldReceive('summarize')->once()->with('')->andReturn([
            'hook_key' => '',
            'hook_version' => '',
            'source_path' => '',
            'content_mode' => 'none',
            'items' => [],
            'pipeline' => ['Prompt Markdown', 'Final Prompt'],
        ]);
        $compose = Mockery::mock(PromptHookCompositionPreviewService::class);

        $html = (new PromptCompositionSummaryPresenter($summary, $compose))
            ->renderHtml('', '', 'Only markdown', [], false)
            ->toHtml();

        self::assertStringContainsString('Only markdown', $html);
    }
}
