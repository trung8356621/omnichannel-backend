<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Enums\WorkflowExecutionRole;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Services\ArticleOutlineResolver;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStepDescriptor;

/**
 * Source contract theo step — không fallback lung tung.
 */
final class ContentProjectStepSourceValidator
{
    public function __construct(
        private readonly ArticleOutlineResolver $outlineResolver,
    ) {}

    /**
     * @return list<string>
     */
    public function requirementsFor(ContentProjectStepDescriptor $step): array
    {
        if ($step->sourceRequirements !== []) {
            return $step->sourceRequirements;
        }

        return $this->defaultRequirements($step);
    }

    /**
     * @return list<string>
     */
    public function defaultRequirements(ContentProjectStepDescriptor $step): array
    {
        $role = WorkflowExecutionRole::tryFromMixed($step->executionRole);
        if ($role instanceof WorkflowExecutionRole) {
            return match ($role) {
                WorkflowExecutionRole::ArticleOutlineGenerate => ['title', 'keyword'],
                WorkflowExecutionRole::ArticleContentGenerate => ['outline'],
                WorkflowExecutionRole::ArticleContentImprove => ['article_body'],
                WorkflowExecutionRole::ArticleImageGenerate => ['article_body', 'image_settings'],
            };
        }

        return match ($step->kind) {
            'outline' => ['title', 'keyword'],
            'content' => ['outline'],
            'improve' => ['article_body'],
            'image', 'typography', 'infographic', 'product_gallery' => ['article_body', 'image_settings'],
            'meta_title', 'meta_description', 'slug' => ['article_body', 'keyword'],
            'faq', 'featured_snippet' => ['article_body'],
            default => ['article_body'],
        };
    }

    /**
     * @return string|null Error message when invalid; null when OK.
     */
    public function validate(SeoProjectTask $task, ContentProjectStepDescriptor $step): ?string
    {
        $requirements = $this->requirementsFor($step);
        $article = $this->resolveArticle($task);

        foreach ($requirements as $req) {
            $error = match ($req) {
                'title' => $this->requireTitle($task, $article),
                'keyword' => $this->requireKeyword($task, $article),
                'description', 'brief' => null,
                'outline' => $this->requireOutline($article, $step),
                'article_body' => $this->requireArticleBody($article, $step),
                'image_settings' => null,
                default => null,
            };
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private function resolveArticle(SeoProjectTask $task): ?SeoArticle
    {
        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId <= 0) {
            return null;
        }

        $article = SeoArticle::query()->find($articleId);

        return $article instanceof SeoArticle ? $article : null;
    }

    private function requireTitle(SeoProjectTask $task, ?SeoArticle $article): ?string
    {
        $title = trim((string) ($article?->title ?? $task->title ?? ''));
        if ($title === '') {
            return 'Thiếu tiêu đề — không thể chạy lại bước này.';
        }

        return null;
    }

    private function requireKeyword(SeoProjectTask $task, ?SeoArticle $article): ?string
    {
        $keyword = trim((string) ($task->keyword ?? $article?->focus_keyword ?? ''));
        if ($keyword === '') {
            // Soft: nhiều task vẫn chạy được không keyword — chỉ warn khi outline.
            return null;
        }

        return null;
    }

    private function requireOutline(?SeoArticle $article, ContentProjectStepDescriptor $step): ?string
    {
        if (! $article instanceof SeoArticle) {
            return 'Không thể chạy lại «'.$step->label.'» vì bài chưa có article.';
        }

        if (! $this->outlineResolver->hasUsableOutline($article)) {
            return 'Không thể chạy lại bước «Viết bài» vì bài chưa có dàn ý hợp lệ.';
        }

        return null;
    }

    private function requireArticleBody(?SeoArticle $article, ContentProjectStepDescriptor $step): ?string
    {
        if (! $article instanceof SeoArticle) {
            return 'Không thể chạy lại «'.$step->label.'» vì bài chưa có article.';
        }

        $body = trim((string) ($article->content ?? ''));
        if ($body === '' && method_exists($article, 'getAttribute')) {
            $body = trim((string) $article->getAttribute('content'));
        }
        // Soft gate: bài đã tồn tại là đủ cho hầu hết post-content steps (body có thể nằm meta).
        if ($body === '' && (int) ($article->id ?? 0) <= 0) {
            return 'Không thể chạy lại «'.$step->label.'» vì bài chưa có nội dung.';
        }

        return null;
    }
}
