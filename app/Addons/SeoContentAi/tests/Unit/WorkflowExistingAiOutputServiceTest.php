<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\WorkflowExecutionRole;
use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Services\WorkflowExistingAiOutputService;
use PHPUnit\Framework\TestCase;

final class WorkflowExistingAiOutputServiceTest extends TestCase
{
    public function test_it_reuses_an_existing_outline(): void
    {
        $article = new SeoArticle;
        $article->setRelation('articleMetas', collect([
            (new ArticleMeta)->forceFill([
                'meta_key' => 'seo_article_outline',
                'meta_value' => '# Dàn ý đã có',
            ]),
        ]));
        $prompt = (new SeoPrompt)->forceFill(['name' => 'Anything']);

        $reuse = (new WorkflowExistingAiOutputService)->resolve(
            ['data' => ['execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value]],
            $prompt,
            $article,
        );

        self::assertSame(WorkflowExistingAiOutputService::TYPE_OUTLINE, $reuse['type']);
        self::assertSame('# Dàn ý đã có', $reuse['output']);
    }

    public function test_it_reuses_existing_content_for_the_article_writer_node(): void
    {
        $article = (new SeoArticle)->forceFill(['body' => '<p>Nội dung đã có</p>']);
        $prompt = (new SeoPrompt)->forceFill(['name' => 'Anything']);

        $reuse = (new WorkflowExistingAiOutputService)->resolve([
            'data' => [
                'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                'mergeOutlineToSave' => true,
            ],
        ], $prompt, $article);

        self::assertSame(WorkflowExistingAiOutputService::TYPE_CONTENT, $reuse['type']);
        self::assertSame('<p>Nội dung đã có</p>', $reuse['output']);
    }

    public function test_prompt_name_alone_does_not_detect_outline(): void
    {
        $prompt = (new SeoPrompt)->forceFill(['name' => 'Dàn ý bài viết outline']);
        self::assertNull((new WorkflowExistingAiOutputService)->outputType([], $prompt));
    }
}
