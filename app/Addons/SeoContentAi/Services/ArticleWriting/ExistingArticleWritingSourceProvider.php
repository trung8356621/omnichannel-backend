<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ArticleWriting;

use App\Addons\SeoContentAi\Contracts\ArticleWritingSourceProvider;
use App\Addons\SeoContentAi\Enums\ArticleWritingSourceType;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleGenerationInputResolver;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
use App\Addons\SeoContentAi\Services\WorkflowParserService;
use App\Addons\SeoContentAi\Support\ArticleGenerationSourceResult;
use App\Addons\SeoContentAi\Support\ArticleWritingInput;

/**
 * Canonical body hiện tại (HTML→Markdown qua converter sẵn có).
 */
final class ExistingArticleWritingSourceProvider implements ArticleWritingSourceProvider
{
    public function __construct(
        private readonly WordPressArticleContentService $wordPressContent,
        private readonly WorkflowParserService $workflowParser,
        private readonly ArticleGenerationInputResolver $outlineResolver,
    ) {}

    public function sourceType(): ArticleWritingSourceType
    {
        return ArticleWritingSourceType::ExistingArticle;
    }

    public function resolve(
        array $variables,
        ?SeoArticle $article = null,
        ?ArticleGenerationSourceResult $outlineFromWorkflow = null,
    ): ArticleWritingInput {
        unset($outlineFromWorkflow);

        $title = trim((string) ($variables['post_title'] ?? $variables['title'] ?? ''));
        $keyword = trim((string) ($variables['focus_keyword'] ?? $variables['keyword'] ?? ''));
        $description = trim((string) ($variables['secondary_description'] ?? ''));
        $articleId = $article instanceof SeoArticle
            ? (int) $article->getKey()
            : (isset($variables['article_id']) ? (int) $variables['article_id'] : null);

        $body = trim((string) (
            $variables['article_writing_raw_input']
            ?? $variables['post_content']
            ?? $variables['existing_body']
            ?? ''
        ));

        if ($body === '') {
            $candidate = trim((string) ($variables['input'] ?? ''));
            if ($candidate !== ''
                && empty($variables['article_writing_formatted'])
                && ! $this->outlineResolver->isValidArtifact($candidate)
            ) {
                $body = $candidate;
            }
        }

        if ($body === '' && $article instanceof SeoArticle) {
            $html = trim($this->wordPressContent->resolveEditorHtml($article));
            $body = $this->workflowParser->convertHtmlFragmentToMarkdown($html);
            if ($title === '') {
                $title = trim((string) ($article->title ?? ''));
            }
        }

        if ($body === '') {
            throw new \InvalidArgumentException(
                'Bài viết không có nội dung để viết lại toàn bộ.',
            );
        }

        return ArticleWritingInput::fromExistingArticleBody(
            bodyMarkdown: $body,
            title: $title,
            keyword: $keyword,
            description: $description,
            articleId: $articleId > 0 ? $articleId : null,
            metadata: [
                'article_length' => $variables['article_length'] ?? null,
                'artifact_hash' => hash('sha256', $body),
            ],
        );
    }
}
