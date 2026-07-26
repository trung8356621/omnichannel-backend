<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Enums\WorkflowExecutionRole;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookBinding;

final class WorkflowExistingAiOutputService
{
    public const TYPE_OUTLINE = 'outline';

    public const TYPE_CONTENT = 'content';

    /**
     * @param  array<string, mixed>  $node
     * @return null|array{type: string, output: string, message: string}
     */
    public function resolve(
        array $node,
        SeoPrompt $prompt,
        ?SeoArticle $article,
        bool $allowReuse = true,
    ): ?array {
        if (! $allowReuse) {
            return null;
        }

        if (! $article instanceof SeoArticle) {
            return null;
        }

        $type = $this->outputType($node, $prompt);
        if ($type === null) {
            return null;
        }

        if ($type === self::TYPE_CONTENT) {
            $body = trim((string) ($article->body ?? ''));

            return $body !== ''
                ? [
                    'type' => self::TYPE_CONTENT,
                    'output' => $body,
                    'message' => 'Bỏ qua AI: bài viết đã có nội dung.',
                ]
                : null;
        }

        if (! $article->relationLoaded('articleMetas')) {
            $article->load('articleMetas');
        }
        $outline = trim((string) (
            $article->articleMetas
                ->firstWhere('meta_key', 'seo_article_outline')
                ?->meta_value ?? ''
        ));

        return $outline !== ''
            ? [
                'type' => self::TYPE_OUTLINE,
                'output' => $outline,
                'message' => 'Bỏ qua AI: bài viết đã có dàn ý.',
            ]
            : null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function outputType(array $node, SeoPrompt $prompt): ?string
    {
        $role = WorkflowExecutionRole::tryFromMixed(
            $node['data']['execution_role'] ?? null,
        );
        if ($role === WorkflowExecutionRole::ArticleContentGenerate) {
            return self::TYPE_CONTENT;
        }
        if ($role === WorkflowExecutionRole::ArticleOutlineGenerate) {
            return self::TYPE_OUTLINE;
        }

        // Explicit Builder flag (content node) — không đoán theo tên Prompt.
        if ((bool) ($node['data']['mergeOutlineToSave'] ?? false)) {
            return self::TYPE_CONTENT;
        }

        try {
            $binding = PromptHookBinding::tryFromPrompt($prompt);
            $hook = trim((string) ($binding?->hookKey ?? ''));
            if ($hook === 'article.outline.generate') {
                return self::TYPE_OUTLINE;
            }
            if (str_starts_with($hook, 'article.content.')) {
                return self::TYPE_CONTENT;
            }
        } catch (\InvalidArgumentException) {
            // legacy prompt không có hook
        }

        return null;
    }
}
